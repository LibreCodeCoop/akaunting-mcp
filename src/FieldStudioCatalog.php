<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Exact installed Field Studio REST API contract. No arbitrary HTTP proxy.
 * Module owns record ACL, validation, transactionality and company selection.
 */
final class FieldStudioCatalog
{
    private const ENTITIES = ['invoice','bill','transaction','contact','item','document_item'];
    private const FIELD_TYPES = [
        'text','textarea','email','url','integer','decimal','percent','date','time',
        'datetime','boolean','select','multiselect','contact',
    ];
    private const READ = [
        'api.field-studio.definitions.index' => ['path'=>'/v1/field-studio/definitions',
            'permission'=>'read-settings-company','filters'=>['entity_type']],
        'api.field-studio.definitions.show' => ['path'=>'/v1/field-studio/definitions/{definition}',
            'permission'=>'read-settings-company','filters'=>[]],
        'api.field-studio.records.search' => ['path'=>'/v1/field-studio/records',
            'permission'=>'native-record-read','filters'=>['entity_type','field_key','value','limit']],
        'api.field-studio.values.show' => [
            'path'=>'/v1/field-studio/records/{entity_type}/{entity_id}/values',
            'permission'=>'native-record-read','filters'=>[]],
        'api.field-studio.audit.index' => ['path'=>'/v1/field-studio/audit',
            'permission'=>'read-settings-company-or-record-read',
            'filters'=>['entity_type','entity_id','limit']],
    ];
    private const WRITE = [
        'api.field-studio.definitions.store' => ['path'=>'/v1/field-studio/definitions','methods'=>['POST'],
            'permission'=>'update-settings-company'],
        'api.field-studio.definitions.update' => ['path'=>'/v1/field-studio/definitions/{definition}',
            'methods'=>['PUT','PATCH'],'permission'=>'update-settings-company'],
        'api.field-studio.definitions.destroy' => ['path'=>'/v1/field-studio/definitions/{definition}',
            'methods'=>['DELETE'],'permission'=>'update-settings-company'],
        'api.field-studio.values.create' => ['path'=>'/v1/field-studio/records/{entity_type}/{entity_id}/values',
            'methods'=>['POST'],'permission'=>'native-record-update'],
        'api.field-studio.values.patch' => ['path'=>'/v1/field-studio/records/{entity_type}/{entity_id}/values',
            'methods'=>['PATCH'],'permission'=>'native-record-update'],
        'api.field-studio.values.replace' => ['path'=>'/v1/field-studio/records/{entity_type}/{entity_id}/values',
            'methods'=>['PUT'],'permission'=>'native-record-update'],
        'api.field-studio.values.delete' => [
            'path'=>'/v1/field-studio/records/{entity_type}/{entity_id}/values/{field_key}',
            'methods'=>['DELETE'],'permission'=>'native-record-update'],
    ];

    /** @return list<array<string,mixed>> */
    public function listRead(): array
    {
        $result=[];
        foreach (self::READ as $name=>$spec) {
            $result[]=[
                'route'=>$name,'uri'=>'api'.$spec['path'],'source'=>'module',
                'parameters'=>$this->params($spec['path']),
                'required_permission_hints'=>[$spec['permission']],
                'filters'=>$spec['filters'],
            ];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function listWrite(): array
    {
        $result=[];
        foreach (self::WRITE as $name=>$spec) {
            $result[]=[
                'route'=>$name,'uri'=>'api'.$spec['path'],'source'=>'module',
                'methods'=>$spec['methods'],'parameters'=>$this->params($spec['path']),
                'required_permission_hints'=>[$spec['permission']],
                'human_approval'=>'required_for_every_request',
            ];
        }
        return $result;
    }

    /** @return array{path:string,route:array<string,mixed>} */
    public function resolveRead(string $name,array $params): array
    {
        $spec=self::READ[$name]??null;
        if (!is_array($spec)) throw new RuntimeException('Unreviewed Field Studio read');
        return ['path'=>$this->render($spec['path'],$params),'route'=>$spec];
    }

    /** @return array{path:string,method:string,route:array<string,mixed>} */
    public function resolveWrite(string $name,string $method,array $params): array
    {
        $spec=self::WRITE[$name]??null;
        if (!is_array($spec) || !in_array($method,$spec['methods'],true)) {
            throw new RuntimeException('Unreviewed Field Studio mutation');
        }
        return ['path'=>$this->render($spec['path'],$params),'method'=>$method,'route'=>$spec];
    }

    public function permitsRead(string $path): bool
    {
        foreach (self::READ as $spec) {
            if ($this->pathMatches($spec['path'],$path)) return true;
        }
        return false;
    }

    public function permitsWrite(string $method,string $path): bool
    {
        foreach (self::WRITE as $spec) {
            if (in_array($method,$spec['methods'],true)
                && $this->pathMatches($spec['path'],$path)) return true;
        }
        return false;
    }

    /** @param array<array-key,mixed> $filters
     *  @return array<string,int|string>
     */
    public function filters(string $name,array $filters): array
    {
        $spec=self::READ[$name]??null;
        if (!is_array($spec) || (array_is_list($filters) && $filters!==[])) {
            throw new RuntimeException('Invalid Field Studio filters');
        }
        foreach ($filters as $key=>$value) {
            if (!is_string($key) || !in_array($key,$spec['filters'],true)) {
                throw new RuntimeException('Unreviewed Field Studio query filter');
            }
            if ($key==='entity_type') {
                if (!in_array($value,self::ENTITIES,true)) throw new RuntimeException('Invalid entity type');
            } elseif ($key==='field_key') {
                $this->fieldKey($value);
            } elseif ($key==='entity_id') {
                $this->positiveId($value);
            } elseif ($key==='limit') {
                if (!is_int($value) || $value<1 || $value>200) {
                    throw new RuntimeException('Invalid Field Studio limit');
                }
            } elseif ($key==='value') {
                if (!is_string($value) || strlen($value)>10000
                    || preg_match('/[\x00-\x1F\x7F]/',$value)===1) {
                    throw new RuntimeException('Invalid Field Studio search value');
                }
            }
        }
        if ($name==='api.field-studio.records.search') {
            foreach (['entity_type','field_key','value'] as $needed) {
                if (!array_key_exists($needed,$filters)) {
                    throw new RuntimeException('Field Studio search requires exact filters');
                }
            }
        }
        if ($name==='api.field-studio.audit.index'
            && (isset($filters['entity_type']) !== isset($filters['entity_id']))) {
            throw new RuntimeException('Field Studio per-record audit requires both identifiers');
        }
        return $filters;
    }

    public function validateWrite(string $name,string $method,array $params,array $body): void
    {
        $this->resolveWrite($name,$method,$params);
        if (array_key_exists('company_id',$body) || (array_is_list($body) && $body!==[])) {
            throw new RuntimeException('Field Studio does not accept a company payload override');
        }
        if ($method==='DELETE') {
            if ($body!==[]) throw new RuntimeException('Field Studio deletion has no body');
            return;
        }
        if (str_starts_with($name,'api.field-studio.definitions.')) {
            $allowed=[
                'entity_type','field_key','label','field_type','options','translations',
                'description','placeholder','default_value','is_required','is_visible',
                'is_enabled','sort_order','column_span','expected_version',
            ];
            $this->only($body,$allowed);
            $creating=$name==='api.field-studio.definitions.store';
            if ($creating) {
                foreach (['entity_type','field_key','label','field_type'] as $key) {
                    if (!array_key_exists($key,$body)) throw new RuntimeException('Missing field definition');
                }
                if (array_key_exists('expected_version',$body)) {
                    throw new RuntimeException('New field cannot have an expected version');
                }
            } else {
                if (isset($body['entity_type']) || isset($body['field_key'])
                    || !is_int($body['expected_version']??null)
                    || $body['expected_version']<1) {
                    throw new RuntimeException('Field definition update requires version and immutable identity');
                }
            }
            if (isset($body['entity_type'])
                && !in_array($body['entity_type'],self::ENTITIES,true)) {
                throw new RuntimeException('Invalid entity type');
            }
            if (isset($body['field_key'])) $this->fieldKey($body['field_key']);
            if (isset($body['field_type'])
                && !in_array($body['field_type'],self::FIELD_TYPES,true)) {
                throw new RuntimeException('Invalid field type');
            }
            if (isset($body['label'])
                && (!is_string($body['label']) || trim($body['label'])===''
                    || strlen($body['label'])>180)) {
                throw new RuntimeException('Invalid definition label');
            }
            if (isset($body['options']) && (!is_array($body['options'])
                || !array_is_list($body['options']) || count($body['options'])>100)) {
                throw new RuntimeException('Invalid definition options');
            }
            if (is_array($body['options']??null)) {
                foreach ($body['options'] as $option) {
                    if (!is_string($option) || strlen($option)>255) {
                        throw new RuntimeException('Invalid definition option');
                    }
                }
            }
            return;
        }
        $this->only($body,['values','versions']);
        $values=$body['values']??null;
        if (!is_array($values) || count($values)>100
            || (array_is_list($values) && $values!==[])) {
            throw new RuntimeException('Field Studio values object required');
        }
        foreach ($values as $field=>$value) {
            $this->fieldKey($field);
            if (!is_null($value) && !is_bool($value) && !is_int($value)
                && !is_string($value) && !is_array($value)) {
                throw new RuntimeException('Invalid Field Studio value');
            }
            if (is_string($value) && strlen($value)>10000) {
                throw new RuntimeException('Field Studio value exceeds limit');
            }
            if (is_array($value)) {
                if (!array_is_list($value) || count($value)>100) {
                    throw new RuntimeException('Invalid Field Studio selection');
                }
                foreach ($value as $choice) {
                    if (!is_string($choice) || strlen($choice)>255) {
                        throw new RuntimeException('Invalid Field Studio choice');
                    }
                }
            }
        }
        $versions=$body['versions']??[];
        if (!is_array($versions) || (array_is_list($versions) && $versions!==[])) {
            throw new RuntimeException('Invalid Field Studio versions');
        }
        foreach ($versions as $key=>$version) {
            $this->fieldKey($key);
            if (!array_key_exists($key,$values) || !is_int($version)
                || $version<0 || $version>2147483647) {
                throw new RuntimeException('Invalid Field Studio expected version');
            }
        }
    }

    /** Stable hash of the current server-owned target, rechecked after approval. */
    public function snapshot(Api $api,array $creds,int $company,string $name,array $params,array $body): string
    {
        if ($name==='api.field-studio.definitions.store') {
            $path='/v1/field-studio/definitions';
            $query=['company_id'=>$company,'entity_type'=>$body['entity_type']];
        } elseif (str_starts_with($name,'api.field-studio.definitions.')) {
            $path=$this->resolveRead('api.field-studio.definitions.show',$params)['path'];
            $query=['company_id'=>$company];
        } else {
            $path=$this->resolveRead('api.field-studio.values.show',[
                'entity_type'=>$params['entity_type'],'entity_id'=>$params['entity_id'],
            ])['path'];
            $query=['company_id'=>$company];
        }
        $response=$api->get($creds['email'],$creds['password'],$path,$query);
        $data=$this->checkResponse(
            $name==='api.field-studio.definitions.store'
                ? 'api.field-studio.definitions.index'
                : (str_starts_with($name,'api.field-studio.definitions.')
                    ? 'api.field-studio.definitions.show':'api.field-studio.values.show'),
            $company,$response,$params,
        );
        if (str_starts_with($name,'api.field-studio.definitions.')
            && $name!=='api.field-studio.definitions.store'
            && (int)($data['version']??0)!==($body['expected_version']??($data['version']??0))) {
            throw new RuntimeException('Field Studio definition version changed');
        }
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed>|list<mixed> */
    public function checkResponse(string $name,int $company,array $response,array $params=[]): array
    {
        $data=$response['data']??null;
        if (!is_array($data)) throw new RuntimeException('Malformed Field Studio response');
        $single=$name==='api.field-studio.definitions.show';
        if ($single) {
            if (array_is_list($data)
                || (int)($data['id']??0)!==($params['definition']??0)
                || (int)($data['company_id']??0)!==$company) {
                throw new RuntimeException('Field Studio definition company mismatch');
            }
        } elseif (!array_is_list($data)) {
            throw new RuntimeException('Invalid Field Studio collection');
        }
        if ($name==='api.field-studio.records.search') {
            foreach ($data as $id) $this->positiveId($id);
        } else {
            $rows=$single?[$data]:$data;
            foreach ($rows as $row) {
                if (!is_array($row)
                    || (int)($row['company_id']??0)!==$company) {
                    throw new RuntimeException('Field Studio record company mismatch');
                }
                if ($name==='api.field-studio.values.show'
                    && ($row['entity_type']??null)!==($params['entity_type']??null)) {
                    throw new RuntimeException('Field Studio value entity mismatch');
                }
                ReadResponseGuard::assertNestedCompany($row,$company);
            }
        }
        return $data;
    }

    private function render(string $template,array $params): string
    {
        $keys=$this->params($template);
        if (count($keys)!==count($params)
            || array_diff($keys,array_keys($params))!==[]
            || array_diff(array_keys($params),$keys)!==[]) {
            throw new RuntimeException('Invalid Field Studio path parameters');
        }
        foreach ($keys as $key) {
            $value=$params[$key];
            if ($key==='entity_type') {
                if (!in_array($value,self::ENTITIES,true)) {
                    throw new RuntimeException('Unsupported Field Studio entity type');
                }
            } elseif ($key==='field_key') {
                $this->fieldKey($value);
            } else {
                $this->positiveId($value);
            }
            $template=str_replace('{'.$key.'}',(string)$value,$template);
        }
        return $template;
    }

    private function pathMatches(string $template,string $path): bool
    {
        $parts=preg_split('/(\{[a-z_]+\})/',$template,-1,PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) return false;
        $regex='';
        foreach ($parts as $part) {
            $regex.=match ($part) {
                '{entity_type}' => '(?:invoice|bill|transaction|contact|item|document_item)',
                '{field_key}' => '[a-z][a-z0-9_]{1,70}',
                '{entity_id}','{definition}' => '[1-9][0-9]{0,9}',
                default => preg_quote($part,'~'),
            };
        }
        return preg_match('~^'.$regex.'$~D',$path)===1;
    }

    /** @return list<string> */
    private function params(string $template): array
    {
        preg_match_all('/\{([a-z_]+)\}/',$template,$matches);
        return $matches[1];
    }

    private function fieldKey(mixed $key): void
    {
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{1,70}$/D',$key)!==1) {
            throw new RuntimeException('Invalid Field Studio field key');
        }
    }

    private function positiveId(mixed $id): void
    {
        if (!is_int($id) || $id<1 || $id>2147483647) {
            throw new RuntimeException('Invalid Field Studio ID');
        }
    }

    /** @param list<string> $allowed */
    private function only(array $body,array $allowed): void
    {
        if (array_diff(array_keys($body),$allowed)!==[]) {
            throw new RuntimeException('Unexpected Field Studio mutation fields');
        }
    }
}
