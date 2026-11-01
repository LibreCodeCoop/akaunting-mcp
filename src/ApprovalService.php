<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace AkauntingMcp;

use RuntimeException;

/**
 * A mutation cannot be performed by an MCP tool. It is executed only after
 * an independent, authenticated, CSRF-protected browser approval by the same
 * Akaunting user. One approval authorizes exactly one frozen request.
 */
final class ApprovalService
{
    private const TTL = 600;
    private readonly WriteRouteCatalog $routes;

    public function __construct(
        private readonly Store $store,
        private readonly Api $api,
        private readonly string $issuer,
        ?WriteRouteCatalog $routes = null,
    ) {
        $this->routes=$routes ?? new WriteRouteCatalog();
    }

    public function routes(): array
    {
        return [
            'routes'=>$this->routes->list(), 'count'=>count($this->routes->list()),
            'approval'=>'One authenticated browser confirmation for each change',
            'note'=>'Availability is subject to native Akaunting permissions and company access.',
        ];
    }

    public function prepare(string $subject, array $creds, int $company, array $args): array
    {
        $name=$args['route'] ?? null;
        $method=$args['method'] ?? null;
        $params=$args['route_params'] ?? [];
        $body=$args['body'] ?? [];
        $recordType=$args['record_type'] ?? null;
        if (!is_string($name) || !is_string($method) || !is_array($params)
            || !is_array($body) || (array_is_list($params) && $params!==[])
            || (array_is_list($body) && $body!==[])) {
            throw new RuntimeException('Invalid mutation plan');
        }
        $route=$this->routes->resolve($name,$method,$params);
        if ($recordType!==null && (!is_string($recordType)
            || !preg_match('/^[a-z][a-z0-9_-]{0,49}$/D',$recordType))) {
            throw new RuntimeException('Invalid record type');
        }
        $typeSensitive=str_starts_with($name,'api.contacts.')
            || str_starts_with($name,'api.documents.');
        if ($typeSensitive && $recordType===null) {
            throw new RuntimeException('Document/contact record_type required');
        }
        if (!$typeSensitive && $recordType!==null) {
            throw new RuntimeException('Record type not applicable');
        }
        if ($method!=='DELETE' && $body===[]) {
            throw new RuntimeException('Mutation body required');
        }
        $this->validateBody($body,$company);
        if (array_key_exists('company_id',$body) && (int)$body['company_id']!==$company) {
            throw new RuntimeException('Cross-company mutation forbidden');
        }
        // The query always selects the authorized company; the body must be
        // consistent with the query, including during create operations.
        $body['company_id']=$company;
        if ($typeSensitive && isset($body['type']) && $body['type']!==$recordType) {
            throw new RuntimeException('Record type differs from selected authorization context');
        }
        if ($typeSensitive && str_ends_with($name,'.store')) {
            if (!isset($body['type'])) $body['type']=$recordType;
        }
        $this->validateBody($body,$company);
        $original=$this->target($creds,$company,$name,$params,$recordType);
        $plan=[
            'route'=>$name,'method'=>$method,'path'=>$route['path'],
            'route_params'=>$params,'body'=>$body,'record_type'=>$recordType,
            'company_id'=>$company,'target_digest'=>$original,
        ];
        $ticket=bin2hex(random_bytes(32));
        $digest=hash('sha256',$ticket);
        $sealed=$this->store->sealPlan($plan);
        $generation=$this->store->generation($subject);
        $this->store->state(static function (array &$state) use ($digest,$sealed,$subject,$company,$generation): void {
            $pending=array_filter($state['approvals'] ?? [],static fn(array $a): bool =>
                ($a['subject'] ?? null)===$subject && ($a['status'] ?? null)==='pending'
            );
            if (count($pending)>=12 || count($state['approvals'] ?? [])>=300) {
                throw new RuntimeException('Too many pending approvals');
            }
            $state['approvals'][$digest]=[
                'subject'=>$subject,'company_id'=>$company,
                'generation'=>$generation,'sealed'=>$sealed,
                'status'=>'pending','expires'=>time()+self::TTL,
            ];
        });
        return [
            'status'=>'awaiting_human_approval','request_id'=>$ticket,
            'approval_url'=>$this->issuer.'/approve?request='.$ticket,
            'expires_in_seconds'=>self::TTL,'summary'=>$this->summary($plan),
            'next_step'=>'The user must open approval_url, inspect the exact change and authorize with personal Akaunting credentials. The assistant cannot approve this request.',
        ];
    }

    /** @return array{request_id:string, csrf:string, cookie:string, plan:array, expires:int} */
    public function review(string $ticket): array
    {
        $key=self::ticketKey($ticket);
        $csrf=bin2hex(random_bytes(24));
        $cookie=bin2hex(random_bytes(24));
        $entry=$this->store->state(static function (array &$state) use ($key,$csrf,$cookie): array {
            $entry=$state['approvals'][$key] ?? null;
            if (!is_array($entry) || ($entry['status'] ?? null)!=='pending'
                || ($entry['expires'] ?? 0)<=time()) {
                throw new RuntimeException('Approval expired or unavailable');
            }
            $state['approvals'][$key]['csrf']=hash('sha256',$csrf);
            $state['approvals'][$key]['cookie']=hash('sha256',$cookie);
            return $entry;
        });
        return [
            'request_id'=>$ticket,'csrf'=>$csrf,'cookie'=>$cookie,
            'plan'=>$this->store->openPlan($entry['sealed']),
            'expires'=>(int)$entry['expires'],
        ];
    }

    /**
     * The caller is the browser HTML POST, never the model or a tools/call.
     * On approval, the original encrypted plan is executed once.
     */
    public function decide(
        string $ticket, string $csrf, string $cookie, string $decision,
        string $email='', string $password='', string $confirmation='',
    ): array {
        $key=self::ticketKey($ticket);
        if (!in_array($decision,['approve','reject'],true)) {
            throw new RuntimeException('Invalid approval decision');
        }
        $entry=$this->store->state(static function (array &$state) use ($key,$csrf,$cookie): array {
            $item=$state['approvals'][$key] ?? null;
            if (!is_array($item) || ($item['status'] ?? null)!=='pending'
                || ($item['expires'] ?? 0)<=time()
                || !is_string($item['csrf'] ?? null) || !hash_equals($item['csrf'],hash('sha256',$csrf))
                || !is_string($item['cookie'] ?? null) || !hash_equals($item['cookie'],hash('sha256',$cookie))) {
                throw new RuntimeException('Approval session invalid or already consumed');
            }
            return $item;
        });

        if ($decision==='reject') {
            $this->store->state(static function (array &$state) use ($key): void {
                if (($state['approvals'][$key]['status'] ?? null)!=='pending') {
                    throw new RuntimeException('Approval no longer available');
                }
                $state['approvals'][$key]['status']='rejected';
                unset($state['approvals'][$key]['sealed']);
            });
            return ['status'=>'rejected','message'=>'No Akaunting record was changed.'];
        }

        // Bound brute-force attempts against the upstream account for a leaked
        // review link. Re-opening the page never resets this counter.
        $this->store->state(static function (array &$state) use ($key): void {
            $attempts=(int)($state['approvals'][$key]['attempts'] ?? 0);
            if ($attempts>=5 || ($state['approvals'][$key]['status'] ?? '')!=='pending') {
                throw new RuntimeException('Approval attempt limit exceeded');
            }
            $state['approvals'][$key]['attempts']=$attempts+1;
        });
        $subject=(string)$entry['subject'];
        if ($email==='' || $password==='' || !hash_equals($subject,$this->store->subject($email))) {
            throw new RuntimeException('Approval requires the same Akaunting identity');
        }
        $creds=$this->store->load($subject);
        if ((int)$creds['generation']!==(int)$entry['generation']) {
            throw new RuntimeException('Linked Akaunting session has changed');
        }
        $plan=$this->store->openPlan($entry['sealed']);
        if ($plan['method']==='DELETE' && $confirmation!=='EXCLUIR') {
            throw new RuntimeException('Deletion requires explicit confirmation phrase');
        }
        // Reauthentication must use *user-entered* credentials, not the stored
        // API password. An agent with MCP access cannot authorize its own write.
        $this->api->verify($email,$password,(int)$entry['company_id']);
        $this->store->state(static function (array &$state) use ($key,$subject,$entry): void {
            if (($state['approvals'][$key]['status'] ?? null)!=='pending'
                || ($state['approvals'][$key]['subject'] ?? null)!==$subject
                || ($state['approvals'][$key]['generation'] ?? null)!==$entry['generation']) {
                throw new RuntimeException('Approval was already handled');
            }
            $state['approvals'][$key]['status']='executing';
            unset($state['approvals'][$key]['csrf'],$state['approvals'][$key]['cookie']);
        });

        // A crashed or disconnected process leaves "executing" in state.
        // Never retry automatically: an HTTP timeout may follow a completed write.
        try {
            $this->api->verify($creds['email'],$creds['password'],(int)$entry['company_id']);
            $current=$this->target($creds,(int)$entry['company_id'],
                $plan['route'],$plan['route_params'],$plan['record_type']);
            if ($current!==$plan['target_digest']) {
                throw new RuntimeException('Target changed after user reviewed mutation');
            }
            $response=$this->api->mutate(
                $creds['email'],$creds['password'],$plan['method'],
                $plan['path'],(int)$entry['company_id'],$plan['body']
            );
            $this->store->state(static function (array &$state) use ($key,$response): void {
                $state['approvals'][$key]['status']='completed';
                $state['approvals'][$key]['http_status']=$response['http_status'];
                unset($state['approvals'][$key]['sealed']);
            });
            return [
                'status'=>'completed','http_status'=>$response['http_status'],
                'company_id'=>$entry['company_id'],'route'=>$plan['route'],
                'message'=>'Akaunting accepted the approved operation. Review the record through a read tool.',
            ];
        } catch (\Throwable $error) {
            $this->store->state(static function (array &$state) use ($key): void {
                $state['approvals'][$key]['status']='outcome_unconfirmed';
                unset($state['approvals'][$key]['sealed']);
            });
            throw $error;
        }
    }

    public function status(string $subject, string $ticket): array
    {
        $key=self::ticketKey($ticket);
        $row=$this->store->state(static function (array &$state) use ($key,$subject): array {
            $item=$state['approvals'][$key] ?? null;
            if (!is_array($item) || ($item['subject'] ?? null)!==$subject) {
                throw new RuntimeException('Approval does not belong to the current user');
            }
            return $item;
        });
        if ($this->store->generation($subject)!==(int)$row['generation']) {
            throw new RuntimeException('Approval session was revoked');
        }
        return [
            'status'=>$row['status'],'company_id'=>$row['company_id'],
            'http_status'=>$row['http_status']??null,
            'message'=>match ($row['status']) {
                'pending'=>'Awaiting explicit user authorization in the browser.',
                'completed'=>'Approved operation completed. Confirm the result with a read call.',
                'rejected'=>'User rejected the operation; no changes were made.',
                default=>'Execution result not confirmed. Do not retry before reviewing upstream.',
            },
        ];
    }

    /** Protect the target against cross-company writes and changes after approval. */
    private function target(array $creds, int $company, string $route, array $params, ?string $type): ?string
    {
        if ($params===[]) return null;
        $resource=explode('.',$route)[1] ?? '';
        if ($resource==='companies') {
            if (($params['company'] ?? null)!==$company) {
                throw new RuntimeException('Company mismatch');
            }
            $path='/companies/'.$company;
        } elseif ($resource==='users') {
            $path='/users/'.($params['user']??0);
        } elseif ($resource==='documents' && isset($params['document'])) {
            $path='/documents/'.$params['document'];
        } else {
            $singular=match($resource) {
                'companies'=>'company','currencies'=>'currency','categories'=>'category',
                'dashboards'=>'dashboard','reconciliations'=>'reconciliation',
                'transactions'=>'transaction','transfers'=>'transfer','settings'=>'setting',
                'dismissals'=>'dismissal','departments'=>'department','employees'=>'employee',
                'contacts'=>'contact','documents'=>'document','accounts'=>'account',
                'items'=>'item','reports'=>'report','taxes'=>'tax','users'=>'user',
                default=>throw new RuntimeException('Unsupported resource in preflight'),
            };
            if (!isset($params[$singular])) throw new RuntimeException('Missing target ID');
            $path='/'.$resource.'/'.$params[$singular];
        }
        $query=['company_id'=>$company];
        if ($type!==null)$query['search']='type:'.$type;
        $value=$this->api->get($creds['email'],$creds['password'],$path,$query);
        $data=$value['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Cannot verify target record');
        }
        if ($resource==='companies') {
            if ((int)($data['id']??0)!==$company)throw new RuntimeException('Company ID mismatch');
        } elseif ($resource==='users') {
            $memberships=$data['companies']['data'] ?? [];
            if (!is_array($memberships) || !array_any($memberships,static fn(mixed $x): bool =>
                is_array($x) && (int)($x['id']??0)===$company
            )) throw new RuntimeException('User is not assigned to selected company');
        } elseif ((int)($data['company_id']??0)!==$company) {
            throw new RuntimeException('Target does not belong to selected company');
        }
        if ($type!==null && ($data['type']??null)!==$type) {
            throw new RuntimeException('Target record type changed');
        }
        if ($resource==='documents' && isset($params['transaction'])) {
            $nested=$this->api->get(
                $creds['email'],$creds['password'],
                '/documents/'.$params['document'].'/transactions/'.$params['transaction'],$query
            );
            $child=$nested['data'] ?? null;
            if (!is_array($child) || (int)($child['document_id']??0)!==$params['document']
                || (int)($child['company_id']??0)!==$company) {
                throw new RuntimeException('Transaction does not belong to selected document');
            }
            $data=['parent'=>$data,'child'=>$child];
        }
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    private function validateBody(array $body,int $company,int $depth=0): void
    {
        if ($depth>8 || count($body)>120)throw new RuntimeException('Write body exceeds limits');
        foreach ($body as $key=>$value) {
            if (is_string($key) && (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,80}$/D',$key))) {
                throw new RuntimeException('Invalid mutation field name');
            }
            if ($key==='company_id' && (!is_int($value) || $value!==$company)) {
                throw new RuntimeException('Cross-company payload not allowed');
            }
            if ($key==='companies' && is_array($value)) {
                foreach ($value as $record) {
                    $id=is_array($record) ? ($record['id']??null) : $record;
                    if (!is_int($id) || $id!==$company) {
                        throw new RuntimeException('Cross-company membership change forbidden');
                    }
                }
            }
            if (is_array($value))$this->validateBody($value,$company,$depth+1);
            elseif (!is_null($value) && !is_bool($value) && !is_int($value)
                && !is_float($value) && !is_string($value)) {
                throw new RuntimeException('Invalid mutation value type');
            }
        }
        if (strlen(json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>30000) {
            throw new RuntimeException('Mutation body too large');
        }
    }

    public function summary(array $plan): array
    {
        return [
            'method'=>$plan['method'],'route'=>$plan['route'],
            'company_id'=>$plan['company_id'],
            'target_ids'=>$plan['route_params'],
            'record_type'=>$plan['record_type'],
            'body'=>$this->redactBody($plan['body']),
            'destructive'=>$plan['method']==='DELETE',
        ];
    }

    private function redactBody(array $body): array
    {
        foreach ($body as $key=>&$value) {
            if (is_string($key) && preg_match('/password|secret|token|api.?key|private.?key|credential/i',$key)) {
                $value='[REDACTED]';
            } elseif (is_array($value)) {
                $value=$this->redactBody($value);
            }
        }
        unset($value);
        return $body;
    }

    private static function ticketKey(string $ticket): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/D',$ticket)) {
            throw new RuntimeException('Invalid approval request ID');
        }
        return hash('sha256',$ticket);
    }
}
