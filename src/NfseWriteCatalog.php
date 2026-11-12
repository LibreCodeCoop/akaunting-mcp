<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use DateTimeImmutable;
use RuntimeException;

/**
 * Small, reviewed NFS-e mutation surface. The module remains the fiscal
 * authority; this catalog only restricts MCP proposals and their approval.
 * No certificate, Vault credential or arbitrary path. Fiscal writes require
 * individual human approval and the installed module's issuance safeguards.
 */
final class NfseWriteCatalog
{
    /** @var array<string,array{method:string,path:string,permission:string}> */
    private const ROUTES = [
        'api.nfse.dps.advance' => [
            'method'=>'POST','path'=>'/nfse/dps/advance',
            'permission'=>'update-nfse-settings',
        ],
        'api.nfse.bulk.dispatch' => [
            'method'=>'POST','path'=>'/nfse/bulk/dispatch',
            'permission'=>'update-sales-invoices',
        ],
        'api.nfse.settings.company-profiles.update' => [
            'method'=>'PATCH', 'path'=>'/nfse/settings/company-profiles/{companyId}',
            'permission'=>'update-nfse-settings',
        ],
        'api.nfse.settings.tax-associations.store' => [
            'method'=>'POST', 'path'=>'/nfse/settings/tax-associations',
            'permission'=>'update-nfse-settings',
        ],
        'api.nfse.settings.tax-associations.revise' => [
            'method'=>'PATCH', 'path'=>'/nfse/settings/tax-associations/{associationId}',
            'permission'=>'update-nfse-settings',
        ],
        'api.nfse.settings.approximate-taxes.store' => [
            'method'=>'POST', 'path'=>'/nfse/settings/approximate-taxes',
            'permission'=>'update-nfse-settings',
        ],
        // Reviewed auxiliary updates introduced by NFS-e #497.
        'api.nfse.settings.email-footer.update' => [
            'method'=>'PATCH', 'path'=>'/nfse/settings/email-footer',
            'permission'=>'update-nfse-settings',
        ],
        // Only non-secret artifact policies; credential entry stays in the
        // protected native browser flow. Never expose Vault/PFX via MCP.
        'api.nfse.settings.artifacts.update' => [
            'method'=>'PATCH', 'path'=>'/nfse/settings/artifacts',
            'permission'=>'update-nfse-settings',
        ],
        'api.nfse.invoices.email-preferences.store' => [
            'method'=>'POST', 'path'=>'/nfse/invoices/{invoice}/email-preferences',
            'permission'=>'update-sales-invoices',
        ],
        'api.nfse.invoices.refresh-all' => [
            'method'=>'POST', 'path'=>'/nfse/invoices/refresh-all',
            'permission'=>'update-sales-invoices',
        ],
        'api.nfse.invoices.fiscal-decision.store' => [
            'method'=>'POST','path'=>'/nfse/invoices/{invoice}/fiscal-decision',
            'permission'=>'update-sales-invoices',
        ],
        'api.nfse.settings.category-issuance.store' => [
            'method'=>'POST','path'=>'/nfse/settings/category-issuance',
            'permission'=>'update-nfse-settings',
        ],
        'api.nfse.adn.documents.ignore' => [
            'method'=>'POST','path'=>'/nfse/adn/documents/{document}/ignore',
            'permission'=>'update-purchases-bills',
        ],
        'api.nfse.adn.documents.import' => [
            'method'=>'POST','path'=>'/nfse/adn/documents/{document}/import',
            'permission'=>'create-purchases-bills',
        ],
        'api.nfse.invoices.fiscal-event-evidence.store' => [
            'method'=>'POST', 'path'=>'/nfse/invoices/{invoice}/fiscal-event-evidence',
            'permission'=>'update-sales-invoices',
        ],
        'api.nfse.invoices.emit' => [
            'method'=>'POST', 'path'=>'/nfse/invoices/{invoice}/emit',
            'permission'=>'update-sales-invoices',
        ],
        'api.nfse.invoices.reemit' => [
            'method'=>'POST', 'path'=>'/nfse/invoices/{invoice}/reemit',
            'permission'=>'update-sales-invoices',
        ],
        'api.nfse.invoices.substitute' => [
            'method'=>'POST', 'path'=>'/nfse/invoices/{invoice}/substitute',
            'permission'=>'update-sales-invoices',
        ],
        'api.nfse.invoices.refresh' => [
            'method'=>'POST', 'path'=>'/nfse/invoices/{invoice}/refresh',
            'permission'=>'update-sales-invoices',
        ],
        'api.nfse.invoices.cancel' => [
            'method'=>'DELETE', 'path'=>'/nfse/invoices/{invoice}',
            'permission'=>'update-sales-invoices',
        ],
    ];

    private const PROFILE_FIELDS = [
        'uf','municipio_ibge','municipio_nome','prestador_inscricao_municipal',
        'prestador_telefone','prestador_email','provider_establishment_confirmed',
        'sandbox_mode','dps_series','emission_policy','opcao_simples_nacional',
        'regime_especial_tributacao','regime_apuracao_simples_nacional',
        'tributacao_issqn','tipo_retencao_iss','issqn_pais_resultado',
        'issqn_tipo_imunidade','issqn_tipo_suspensao',
        'issqn_numero_processo_suspensao','ibs_cbs_ind_final',
        'ibs_cbs_ind_dest','federal_piscofins_situacao_tributaria',
        'federal_piscofins_tipo_retencao','ibs_cbs_enabled',
        'ibs_cbs_c_ind_op','ibs_cbs_cst','ibs_cbs_c_class_trib',
    ];

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $out=[];
        foreach (self::ROUTES as $name=>$spec) {
            preg_match_all('/\{([a-zA-Z]+)\}/', $spec['path'], $matches);
            $fiscalCommand=in_array($name,[
                'api.nfse.invoices.emit','api.nfse.invoices.reemit',
                'api.nfse.invoices.substitute','api.nfse.invoices.cancel',
                'api.nfse.bulk.dispatch',
            ],true);
            $out[]=[
                'route'=>$name, 'methods'=>[$spec['method']],
                'uri'=>'{company_id}/api'.$spec['path'],
                'parameters'=>$matches[1], 'source'=>'module',
                'required_permission_hints'=>[$spec['permission']],
                'human_approval'=>'required_for_every_request',
                'enabled'=>!$fiscalCommand || getenv('MCP_NFSE_FISCAL_WRITES_ENABLED')==='true',
                'fiscal_environment'=>$name==='api.nfse.bulk.dispatch'
                    ? 'production_restricted_only'
                    : ($fiscalCommand
                        ? 'production_and_restricted'
                        : (in_array($name,['api.nfse.dps.advance','api.nfse.invoices.refresh',
                            'api.nfse.invoices.refresh-all'],true)
                            ? 'company_scoped_operational_reconciliation'
                            : 'company_scoped_configuration')),
            ];
        }
        return $out;
    }

    /** @return array{path:string,method:string,route:array<string,mixed>} */
    public function resolve(string $name, string $method, array $params): array
    {
        $spec=self::ROUTES[$name] ?? null;
        if ($spec===null || $method!==$spec['method']) {
            throw new RuntimeException('Unreviewed NFS-e mutation');
        }
        preg_match_all('/\{([a-zA-Z]+)\}/', $spec['path'], $matches);
        $expected=$matches[1];
        if (count($params)!==count($expected)
            || array_diff($expected,array_keys($params))!==[]
            || array_diff(array_keys($params),$expected)!==[]) {
            throw new RuntimeException('Invalid NFS-e mutation path parameters');
        }
        $path=$spec['path'];
        foreach ($expected as $key) {
            $id=$params[$key];
            if (!is_int($id) || $id<=0 || $id>2147483647) {
                throw new RuntimeException('NFS-e mutation IDs must be positive integers');
            }
            $path=str_replace('{'.$key.'}',(string)$id,$path);
        }
        return ['path'=>$path,'method'=>$method,'route'=>$spec];
    }

    public function permits(string $method, string $path): bool
    {
        foreach (self::ROUTES as $spec) {
            if ($method!==$spec['method']) continue;
            $pattern=preg_replace(
                '/\\\{[a-zA-Z]+\\\}/', '[1-9][0-9]{0,9}',
                preg_quote($spec['path'],'~')
            );
            if (is_string($pattern) && preg_match('~^'.$pattern.'$~D',$path)===1) {
                return true;
            }
        }
        return false;
    }

    /** Validate before preparing a single-use approval; the module validates again. */
    public function validate(string $name, array $body, int $company, array $params): void
    {
        if (!isset(self::ROUTES[$name]) || $body===[] || array_is_list($body)
            || array_key_exists('company_id',$body)) {
            throw new RuntimeException('Invalid NFS-e mutation body');
        }
        if ($name==='api.nfse.settings.artifacts.update') {
            $this->only($body,['nfse']);
            $values=$body['nfse']??null;
            if (!is_array($values) || $values===[] || array_is_list($values)) {
                throw new RuntimeException('Non-secret WebDAV policy values required');
            }
            $this->only($values,[
                'webdav_path_template','webdav_filename_template','webdav_path_scope',
                'webdav_store_xml','webdav_store_pdf',
            ]);
            foreach (['webdav_store_xml','webdav_store_pdf'] as $flag) {
                if (array_key_exists($flag,$values) && !is_bool($values[$flag])) {
                    throw new RuntimeException('WebDAV archive flag must be boolean');
                }
            }
            if (array_key_exists('webdav_path_scope',$values)
                && !in_array($values['webdav_path_scope'],['company','configured'],true)) {
                throw new RuntimeException('Invalid WebDAV path isolation scope');
            }
            foreach (['webdav_path_template','webdav_filename_template'] as $templateField) {
                if (!array_key_exists($templateField,$values)) continue;
                $value=$values[$templateField];
                if (!is_string($value) || strlen($value)<1 || strlen($value)>255
                    || trim($value,'/ .')===''
                    || preg_match('/^[a-zA-Z0-9_{}.\\/\\- ]+$/D',$value)!==1
                    || str_contains($value,'..') || str_contains($value,'//')
                    || ($templateField==='webdav_filename_template' && str_contains($value,'/'))) {
                    throw new RuntimeException('Invalid WebDAV archive naming template');
                }
                $withoutTokens=preg_replace(
                    '/\\{(?:cnpj|year|month|day|month_name|nfse_number|chave_acesso|customer_name)\\}/',
                    '',$value,
                );
                if (!is_string($withoutTokens)
                    || str_contains($withoutTokens,'{') || str_contains($withoutTokens,'}')) {
                    throw new RuntimeException('Unrecognized WebDAV archive placeholder');
                }
            }
            if (($values['webdav_path_scope']??null)==='configured'
                && (!is_string($values['webdav_path_template']??null)
                    || !str_contains($values['webdav_path_template'],'{cnpj}'))) {
                throw new RuntimeException('Dedicated WebDAV scope requires an explicit issuer CNPJ path');
            }
            return;
        }
        if ($name==='api.nfse.settings.email-footer.update') {
            $this->only($body,['footer_message','footer_url']);
            $message=$body['footer_message']??null;
            $url=$body['footer_url']??null;
            if (!is_string($message) || mb_strlen(trim($message))<1 || mb_strlen($message)>400
                || preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/',$message)===1
                || !is_string($url) || strlen($url)>512
                || filter_var($url,FILTER_VALIDATE_URL)===false
                || parse_url($url,PHP_URL_SCHEME)!=='https'
                || parse_url($url,PHP_URL_USER)!==null
                || parse_url($url,PHP_URL_PASS)!==null
                || preg_match('/[\\x00-\\x20\\x7F]/',$url)===1) {
                throw new RuntimeException('Invalid fiscal email footer update');
            }
            return;
        }
        if ($name==='api.nfse.invoices.email-preferences.store') {
            $this->only($body,['subject','body']);
            $subject=$body['subject']??null;
            $content=$body['body']??null;
            if (!is_string($subject) || mb_strlen(trim($subject))===0 || mb_strlen($subject)>255
                || !is_string($content) || mb_strlen(trim($content))===0 || mb_strlen($content)>12000
                || preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/',$subject.$content)===1) {
                throw new RuntimeException('Invalid company email template');
            }
            return;
        }
        if ($name==='api.nfse.invoices.refresh-all') {
            $this->only($body,['confirmed']);
            if (($body['confirmed']??null)!==true) {
                throw new RuntimeException('Explicit approval of fiscal status refresh required');
            }
            return;
        }
        if ($name==='api.nfse.invoices.fiscal-decision.store') {
            $this->only($body,['decision','reason','justification','evidence_reference']);
            $decision=$body['decision']??null;
            $reason=$body['reason']??null;
            $justification=$body['justification']??null;
            $evidence=$body['evidence_reference']??null;
            if (!in_array($decision,['do_not_issue','not_applicable','issue','reversed'],true)
                || !in_array($reason,['operational_review','external_issuance',
                    'non_service_revenue','tax_recovery','financial_adjustment',
                    'other','reconsidered'],true)
                || !is_string($justification)
                || mb_strlen(trim($justification))<12 || mb_strlen($justification)>4000
                || preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/',$justification)===1
                || ($evidence!==null && (!is_string($evidence)
                    || mb_strlen($evidence)>255
                    || preg_match('/[\\x00-\\x1F\\x7F]/',$evidence)===1))
                || ($decision==='not_applicable'
                    && (!is_string($evidence) || mb_strlen(trim($evidence))<5))
                || ($decision==='reversed' && $reason!=='reconsidered')) {
                throw new RuntimeException('Invalid operator fiscal decision evidence');
            }
            return;
        }
        if ($name==='api.nfse.settings.category-issuance.store') {
            $this->only($body,['category_id','mode','justification']);
            $justification=$body['justification']??null;
            if (!is_int($body['category_id']??null) || $body['category_id']<=0
                || !in_array($body['mode']??null,['issue','do_not_issue'],true)
                || !is_string($justification)
                || mb_strlen(trim($justification))<12 || mb_strlen($justification)>4000
                || preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/',$justification)===1) {
                throw new RuntimeException('Invalid fiscal category authorization');
            }
            return;
        }
        if ($name==='api.nfse.adn.documents.ignore') {
            $this->only($body,['confirmed']);
            if (($body['confirmed']??null)!==true) {
                throw new RuntimeException('ADN document dismissal requires explicit approval');
            }
            return;
        }
        if ($name==='api.nfse.adn.documents.import') {
            // Creating a vendor is a separate permission and approval workflow.
            // This route is restricted to previously reviewed company vendors.
            $this->only($body,[
                'contact_id','category_id','item_id','issued_at','due_at','confirmed',
            ]);
            foreach (['contact_id','category_id','item_id'] as $field) {
                if (!is_int($body[$field]??null)
                    || $body[$field]<1 || $body[$field]>2147483647) {
                    throw new RuntimeException('Existing vendor, expense category and item required');
                }
            }
            if (!$this->validDate($body['issued_at']??null)
                || !$this->validDate($body['due_at']??null)
                || $body['due_at']<$body['issued_at']
                || ($body['confirmed']??null)!==true) {
                throw new RuntimeException('Explicit, chronological ADN draft import required');
            }
            return;
        }
        if ($name==='api.nfse.dps.advance') {
            $this->only($body,[
                'expected_next_number','next_number','reason','confirmed',
            ]);
            $expected=$body['expected_next_number']??null;
            $next=$body['next_number']??null;
            $reason=$body['reason']??null;
            if (!is_string($expected) || preg_match('/^[1-9][0-9]{0,14}$/D',$expected)!==1
                || !is_string($next) || preg_match('/^[1-9][0-9]{0,14}$/D',$next)!==1
                || (int)$next<=(int)$expected
                || !is_string($reason) || mb_strlen(trim($reason))<12
                || mb_strlen($reason)>500 || preg_match('/[\x00-\x1F\x7F]/',$reason)===1
                || ($body['confirmed']??null)!==true) {
                throw new RuntimeException('Explicit and evidenced DPS advance required');
            }
            return;
        }
        if ($name==='api.nfse.bulk.dispatch') {
            (new NfseReadOnlyPostCatalog())->validateBody('api.nfse.bulk.plan',$body);
            return;
        }
        if ($name==='api.nfse.invoices.refresh') {
            $this->only($body,['nfse_receipt_id','nfse_force_ajax']);
            if (!is_int($body['nfse_receipt_id']??null)
                || $body['nfse_receipt_id']<=0
                || ($body['nfse_force_ajax']??null)!==true) {
                throw new RuntimeException('Current fiscal receipt required for refresh');
            }
            return;
        }
        if ($name==='api.nfse.settings.company-profiles.update') {
            if (($params['companyId'] ?? null)!==$company) {
                throw new RuntimeException('Fiscal profile company mismatch');
            }
            $this->only($body,['fiscal_parameters']);
            $fields=$body['fiscal_parameters'] ?? null;
            if (!is_array($fields) || $fields===[] || array_is_list($fields)) {
                throw new RuntimeException('Fiscal profile fields required');
            }
            $this->only($fields,self::PROFILE_FIELDS);
            foreach ($fields as $key=>$value) {
                if (in_array($key,['sandbox_mode','ibs_cbs_enabled','provider_establishment_confirmed'],true)) {
                    if (!is_bool($value)) throw new RuntimeException('Fiscal boolean required');
                } elseif ($value!==null && (!is_string($value) || strlen($value)>255)) {
                    throw new RuntimeException('Invalid fiscal profile value');
                }
                // Switching environment or enabling automatic issuance is a
                // separate, single-use approved profile mutation, never a read.
                if ($key==='emission_policy'
                    && !in_array($value,['manual','emit_on_send'],true)) {
                    throw new RuntimeException('Unsupported fiscal emission policy');
                }
            }
            return;
        }
        if ($name==='api.nfse.settings.tax-associations.store'
            || $name==='api.nfse.settings.tax-associations.revise') {
            $revising=str_ends_with($name,'.revise');
            $allowed=[
                'fiscal_identity','fiscal_role','effective_from','effective_until',
                'source_reference','acknowledge',
            ];
            if (!$revising) $allowed[]='tax_id';
            $this->only($body,$allowed);
            if (!$revising && (!is_int($body['tax_id']??null) || $body['tax_id']<=0)) {
                throw new RuntimeException('Existing tax ID required');
            }
            if (!in_array($body['fiscal_identity']??null,
                ['issqn','pis','cofins','csll','irrf','cp','ibs','cbs'],true)
                || !in_array($body['fiscal_role']??null,
                    ['provider_due','withheld_by_payer','estimated_disclosure'],true)
                || ($body['acknowledge']??null)!==true
                || !$this->validDate($body['effective_from']??null)
                || (array_key_exists('effective_until',$body)
                    && $body['effective_until']!==null
                    && (!$this->validDate($body['effective_until'])
                        || $body['effective_until']<$body['effective_from']))
                || !is_string($body['source_reference']??null)
                || strlen(trim($body['source_reference']))<5
                || strlen($body['source_reference'])>255) {
                throw new RuntimeException('Invalid fiscal tax confirmation');
            }
            return;
        }
        if ($name==='api.nfse.settings.approximate-taxes.store') {
            $required=[
                'national_service_code','simples_option','competence_from',
                'source_kind','source_name','source_version','source_reference',
                'federal_percent','state_percent','municipal_percent',
            ];
            $this->only($body,[...$required,'competence_until']);
            foreach ($required as $key) {
                if (!array_key_exists($key,$body) || !is_string($body[$key])) {
                    throw new RuntimeException('Approximate-tax evidence field missing');
                }
            }
            if (preg_match('/^\d{6}$/D',$body['national_service_code'])!==1
                || !in_array($body['simples_option'],['1','2','3'],true)
                || !$this->validDate($body['competence_from'])
                || (isset($body['competence_until'])
                    && (!$this->validDate($body['competence_until'])
                        || $body['competence_until']<$body['competence_from']))
                || !in_array($body['source_kind'],[
                    'independent_estimate','taxpayer_declaration','documented_zero',
                ],true)) {
                throw new RuntimeException('Invalid approximate-tax scope');
            }
            foreach (['federal_percent','state_percent','municipal_percent'] as $key) {
                if (preg_match('/^(?:0|[1-9]\d{0,2})(?:\.\d{1,2})?$/D',$body[$key])!==1) {
                    throw new RuntimeException('Approximate tax must use decimal text');
                }
                if ($body['source_kind']==='documented_zero' && (float)$body[$key]!==0.0) {
                    throw new RuntimeException('Documented zero cannot contain a rate');
                }
            }
            foreach (['source_name'=>[3,160],'source_version'=>[1,80],'source_reference'=>[5,255]] as $key=>$bounds) {
                if (strlen(trim($body[$key]))<$bounds[0] || strlen($body[$key])>$bounds[1]) {
                    throw new RuntimeException('Invalid approximate-tax provenance');
                }
            }
            return;
        }
        if ($name==='api.nfse.invoices.fiscal-event-evidence.store') {
            $this->only($body,[
                'fiscal_group_key','taxable_event_date','source_reference','acknowledge',
            ]);
            if (!$this->validGroupKey($body['fiscal_group_key']??null)
                || !$this->validDate($body['taxable_event_date']??null)
                || !is_string($body['source_reference']??null)
                || strlen(trim($body['source_reference']))<5
                || strlen($body['source_reference'])>255
                || preg_match('/[\x00-\x1F\x7F]/',$body['source_reference'])===1
                || ($body['acknowledge']??null)!==true) {
                throw new RuntimeException('Invalid fiscal event attestation');
            }
            return;
        }
        if ($name==='api.nfse.invoices.emit') {
            $this->only($body,['nfse_force_ajax','nfse_fiscal_group_key']);
            if (($body['nfse_force_ajax']??null)!==true) {
                throw new RuntimeException('Explicit JSON fiscal emission required');
            }
            if (isset($body['nfse_fiscal_group_key'])
                && !$this->validGroupKey($body['nfse_fiscal_group_key'])) {
                throw new RuntimeException('Invalid fiscal group key');
            }
            return;
        }
        if ($name==='api.nfse.invoices.reemit') {
            $this->only($body,['nfse_receipt_id','nfse_force_ajax']);
            if (!is_int($body['nfse_receipt_id']??null)
                || $body['nfse_receipt_id']<=0
                || ($body['nfse_force_ajax']??null)!==true) {
                throw new RuntimeException('Explicit reemission of current cancelled receipt required');
            }
            return;
        }
        if ($name==='api.nfse.invoices.substitute') {
            $this->only($body,[
                'nfse_substitution_receipt_id','nfse_substitution_reason',
                'nfse_substitution_description','nfse_force_ajax',
            ]);
            $reason=$body['nfse_substitution_reason']??null;
            $description=$body['nfse_substitution_description']??null;
            if (!is_int($body['nfse_substitution_receipt_id']??null)
                || $body['nfse_substitution_receipt_id']<=0
                || !in_array($reason,['01','02','03','04','05','99'],true)
                || !is_string($description)
                || strlen(trim($description))<15 || strlen($description)>255
                || preg_match('/[\x00-\x1F\x7F]/',$description)===1
                || ($body['nfse_force_ajax']??null)!==true) {
                throw new RuntimeException('Invalid explicit NFS-e substitution request');
            }
            return;
        }
        $this->only($body,[
            'nfse_receipt_id','cancel_reason','cancel_justification','nfse_force_ajax',
        ]);
        if (!is_int($body['nfse_receipt_id']??null) || $body['nfse_receipt_id']<=0
            || !in_array($body['cancel_reason']??null,['1','2','9'],true)
            || !is_string($body['cancel_justification']??null)
            || strlen(trim($body['cancel_justification']))<15
            || strlen($body['cancel_justification'])>255
            || ($body['nfse_force_ajax']??true)!==true) {
            throw new RuntimeException('Invalid NFS-e cancellation request');
        }
    }

    /**
     * Freeze company-owned, reviewed API evidence before approval and compare
     * it immediately before execution. This is not a legal compliance verdict.
     */
    public function snapshot(
        Api $api, array $creds, int $company, string $route, array $params, array $body
    ): string {
        $reader=new NfseReadService($api);
        $profile=null;
        $snapshot=[];
        // Non-transmitting operations are available to authorized companies
        // even before fiscal issuance is configured. They still require an
        // independent approved state snapshot and native route permissions.
        $noIssuancePrerequisite=in_array($route,[
            'api.nfse.settings.email-footer.update',
            'api.nfse.settings.artifacts.update',
            'api.nfse.invoices.email-preferences.store',
            'api.nfse.settings.category-issuance.store',
            'api.nfse.invoices.fiscal-decision.store',
            'api.nfse.adn.documents.ignore',
            'api.nfse.adn.documents.import',
        ],true);
        if (!$noIssuancePrerequisite) {
            $profileResponse=$reader->read($creds,$company,[
                'route'=>'api.nfse.settings.company-profiles.index',
            ])['response'];
            $profiles=$profileResponse['profiles']??null;
            if (!is_array($profiles)) throw new RuntimeException('Fiscal profile unavailable');
            foreach ($profiles as $candidate) {
                if (is_array($candidate) && ($candidate['company_id']??null)===$company) {
                    $profile=$candidate;
                    break;
                }
            }
            if ($profile===null || ($profile['status']??null)!=='active') {
                throw new RuntimeException('Active fiscal profile required');
            }
            $snapshot['profile']=$profile;
        }
        if ($route==='api.nfse.settings.artifacts.update') {
            $current=$reader->read($creds,$company,[
                'route'=>'api.nfse.settings.artifacts.show',
            ])['response']['data']??null;
            if (!is_array($current)
                || !in_array($current['path_scope']??null,['company','configured'],true)) {
                throw new RuntimeException('Current WebDAV archive policy unavailable');
            }
            // No mutation is prepared using a returned endpoint URL or secret.
            // Changing archival semantics always requires a fresh approval.
            $snapshot['webdav_artifact_policy']=$current;
        } elseif ($route==='api.nfse.settings.email-footer.update') {
            $snapshot['email_footer']=$reader->read($creds,$company,[
                'route'=>'api.nfse.settings.email-footer.show',
            ])['response'];
        } elseif ($route==='api.nfse.invoices.email-preferences.store') {
            $invoiceId=$params['invoice']??null;
            if (!is_int($invoiceId)) throw new RuntimeException('Invoice ID required');
            $invoice=$api->get($creds['email'],$creds['password'],
                '/documents/'.$invoiceId,[
                    'company_id'=>$company,'search'=>'type:invoice',
                ])['data']??null;
            if (!is_array($invoice) || array_is_list($invoice)
                || ($invoice['id']??null)!==$invoiceId
                || (int)($invoice['company_id']??0)!==$company
                || ($invoice['type']??null)!=='invoice'
                || !is_int($invoice['contact_id']??null)
                || $invoice['contact_id']<=0) {
                throw new RuntimeException('Invoice or customer does not belong to selected company');
            }
            ReadResponseGuard::assertNestedCompany($invoice,$company);
            $snapshot['invoice']=$invoice;
            $snapshot['email_preferences']=$reader->read($creds,$company,[
                'route'=>'api.nfse.invoices.email-preferences.show',
                'route_params'=>['invoice'=>$invoiceId],
            ])['response'];
        } elseif ($route==='api.nfse.invoices.refresh-all') {
            $snapshot['ledger']=$reader->read($creds,$company,[
                'route'=>'api.nfse.ledger.index',
                'nfse_filters'=>['page'=>1,'limit'=>50],
            ])['response'];
        } elseif ($route==='api.nfse.settings.category-issuance.store') {
            $catalog=$reader->read($creds,$company,[
                'route'=>'api.nfse.settings.category-issuance.index',
            ])['response']['data']??null;
            if (!is_array($catalog) || !array_is_list($catalog)) {
                throw new RuntimeException('Fiscal category catalog unavailable');
            }
            $matching=array_values(array_filter($catalog,static fn (mixed $item): bool =>
                is_array($item)
                && ($item['id']??null)===$body['category_id']
                && ($item['company_id']??null)===$company
            ));
            if (count($matching)!==1
                || ($body['mode']==='issue' && ($matching[0]['can_issue']??null)!==true)) {
                throw new RuntimeException('Category does not permit the approved fiscal policy');
            }
            $snapshot['category']=$matching[0];
        } elseif ($route==='api.nfse.invoices.fiscal-decision.store') {
            $invoiceId=$params['invoice']??null;
            if (!is_int($invoiceId)) throw new RuntimeException('Invoice ID required');
            $invoice=$api->get($creds['email'],$creds['password'],
                '/documents/'.$invoiceId,[
                    'company_id'=>$company,'search'=>'type:invoice',
                ])['data']??null;
            if (!is_array($invoice) || array_is_list($invoice)
                || ($invoice['id']??null)!==$invoiceId
                || (int)($invoice['company_id']??0)!==$company
                || ($invoice['type']??null)!=='invoice') {
                throw new RuntimeException('Invoice does not belong to selected company');
            }
            ReadResponseGuard::assertNestedCompany($invoice,$company);
            // Fiscal decision history is a live, explicitly annotated
            // read contract. Keep response-field projection, tenancy checks
            // and discovery validation; never bypass it via a static GET.
            $decision=(new ApiBrowser($api))->read($creds,$company,[
                'route'=>'api.nfse.invoices.fiscal-decision.show',
                'route_params'=>['invoice'=>$invoiceId],
            ])['response']['data']??null;
            if (!is_array($decision)
                || !array_key_exists('current',$decision)
                || !array_key_exists('history',$decision)
                || !is_array($decision['history'])) {
                throw new RuntimeException('Fiscal decision history unavailable');
            }
            if ($body['decision']==='reversed'
                && (!is_array($decision['current'])
                    || ($decision['current']['decision']??null)==='reversed')) {
                throw new RuntimeException('No active fiscal decision to reverse');
            }
            $fiscalStatus=$reader->read($creds,$company,[
                'route'=>'api.nfse.invoices.post-emission-status',
                'route_params'=>['invoice'=>$invoiceId],
            ])['response']['data']??null;
            $attempts=$reader->read($creds,$company,[
                'route'=>'api.nfse.invoices.emission-attempts.index',
                'route_params'=>['invoice'=>$invoiceId],
            ])['response'];
            if (!is_array($fiscalStatus) || !is_array($attempts)) {
                throw new RuntimeException('Fiscal state cannot be verified for operator decision');
            }
            $snapshot['invoice']=$invoice;
            $snapshot['decision']=$decision;
            $snapshot['fiscal_status']=$fiscalStatus;
            $snapshot['attempts']=$attempts;
            if ($body['decision']==='issue') {
                $categories=$reader->read($creds,$company,[
                    'route'=>'api.nfse.settings.category-issuance.index',
                ])['response']['data']??null;
                if (!is_array($categories) || !array_is_list($categories)
                    || !is_int($invoice['category_id']??null)) {
                    throw new RuntimeException('Fiscal issuance category unavailable');
                }
                $authorized=array_values(array_filter($categories,static fn (mixed $row): bool =>
                    is_array($row)
                    && ($row['id']??null)===$invoice['category_id']
                    && ($row['company_id']??null)===$company
                    && ($row['can_issue']??null)===true
                    && ($row['policy']['mode']??null)==='issue'
                ));
                if (count($authorized)!==1) {
                    throw new RuntimeException('Invoice category is not authorized to issue');
                }
                $snapshot['category_policy']=$authorized[0];
            }
        } elseif (in_array($route,[
            'api.nfse.adn.documents.ignore','api.nfse.adn.documents.import',
        ],true)) {
            $documentId=$params['document']??null;
            if (!is_int($documentId)) throw new RuntimeException('ADN document ID required');
            $preview=$reader->read($creds,$company,[
                'route'=>'api.nfse.adn.documents.preview',
                'route_params'=>['document'=>$documentId],
            ])['response']['data']??null;
            if (!is_array($preview)
                || ($preview['document_id']??null)!==$documentId
                || !in_array($preview['fiscal_role']??null,['received','intermediated'],true)
                || ($preview['review_status']??null)!=='pending') {
                throw new RuntimeException('ADN document is not pending company review');
            }
            $snapshot['adn_preview']=$preview;
            if ($route==='api.nfse.adn.documents.import') {
                $options=$reader->read($creds,$company,[
                    'route'=>'api.nfse.adn.review-options',
                ])['response']['data']??null;
                if (!is_array($options) || ($options['can_import']??null)!==true) {
                    throw new RuntimeException('Current operator cannot import an ADN draft');
                }
                $mapping=[];
                foreach ([
                    'contact_id'=>'vendors','category_id'=>'categories','item_id'=>'items',
                ] as $key=>$collection) {
                    $choices=$options[$collection]??null;
                    if (!is_array($choices) || !array_is_list($choices)) {
                        throw new RuntimeException('ADN accounting mapping unavailable');
                    }
                    $matches=array_values(array_filter($choices,static fn (mixed $row): bool =>
                        is_array($row) && ($row['id']??null)===$body[$key]
                    ));
                    if (count($matches)!==1) {
                        throw new RuntimeException('ADN mapping does not belong to active company');
                    }
                    $mapping[$collection]=$matches[0];
                }
                $snapshot['adn_mapping']=$mapping;
            }
        } elseif ($route==='api.nfse.dps.advance') {
            $readiness=$reader->read($creds,$company,[
                'route'=>'api.nfse.dps.readiness',
            ])['response']['data']??null;
            if (!is_array($readiness)
                || ($readiness['company_id']??null)!==$company
                || ($readiness['authority_present']??null)!==true
                || ($readiness['local_present']??null)!==true
                || ($readiness['profile_active']??null)!==true
                || !is_string($readiness['series']??null)
                || trim($readiness['series'])===''
                || ($readiness['candidate_number']??null)!==$body['expected_next_number']) {
                throw new RuntimeException('DPS authority or expected next number changed');
            }
            $snapshot['dps_readiness']=$readiness;
        } elseif ($route==='api.nfse.bulk.dispatch') {
            $sandbox=filter_var(
                $profile['fiscal_parameters']['sandbox_mode']??null,
                FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE,
            );
            if ($sandbox!==true || getenv('MCP_NFSE_FISCAL_WRITES_ENABLED')!=='true') {
                throw new RuntimeException('Bulk fiscal dispatch is restricted to sandbox');
            }
            $readiness=$reader->read($creds,$company,[
                'route'=>'api.nfse.dps.readiness',
            ])['response']['data']??null;
            if (!is_array($readiness) || ($readiness['mechanically_ready']??null)!==true
                || ($readiness['environment']??null)!==2) {
                throw new RuntimeException('Sandbox DPS must be ready for bulk dispatch');
            }
            $snapshot['dps_readiness']=$readiness;
            $snapshot['invoices']=[];
            foreach ($body['invoice_ids'] as $invoiceId) {
                $invoice=$api->get($creds['email'],$creds['password'],
                    '/documents/'.$invoiceId,[
                        'company_id'=>$company,'search'=>'type:invoice',
                    ])['data']??null;
                if (!is_array($invoice) || array_is_list($invoice)
                    || ($invoice['id']??null)!==$invoiceId
                    || (int)($invoice['company_id']??0)!==$company
                    || ($invoice['type']??null)!=='invoice') {
                    throw new RuntimeException('Bulk invoice does not belong to selected company');
                }
                ReadResponseGuard::assertNestedCompany($invoice,$company);
                $snapshot['invoices'][]=$invoice;
            }
        } elseif ($route==='api.nfse.invoices.refresh') {
            $invoiceId=$params['invoice']??null;
            if (!is_int($invoiceId)) throw new RuntimeException('Invoice ID required');
            $invoice=$api->get($creds['email'],$creds['password'],
                '/documents/'.$invoiceId,[
                    'company_id'=>$company,'search'=>'type:invoice',
                ])['data']??null;
            if (!is_array($invoice) || array_is_list($invoice)
                || ($invoice['id']??null)!==$invoiceId
                || (int)($invoice['company_id']??0)!==$company
                || ($invoice['type']??null)!=='invoice') {
                throw new RuntimeException('Invoice does not belong to selected company');
            }
            ReadResponseGuard::assertNestedCompany($invoice,$company);
            $status=$reader->read($creds,$company,[
                'route'=>'api.nfse.invoices.post-emission-status',
                'route_params'=>['invoice'=>$invoiceId],
            ])['response']['data']??null;
            if (!is_array($status)
                || ($status['receipt_id']??null)!==$body['nfse_receipt_id']
                || in_array($status['status']??null,['cancelled','substituted'],true)) {
                throw new RuntimeException('Fiscal receipt no longer current for refresh');
            }
            $snapshot['invoice']=$invoice;
            $snapshot['fiscal_status']=$status;
        } elseif (str_starts_with($route,'api.nfse.settings.company-profiles.')) {
            if (($params['companyId']??null)!==$company) {
                throw new RuntimeException('Fiscal profile company mismatch');
            }
        } elseif (str_starts_with($route,'api.nfse.settings.tax-associations.')) {
            $catalog=$reader->read($creds,$company,[
                'route'=>'api.nfse.settings.tax-associations.index',
            ])['response']['data']??null;
            if (!is_array($catalog) || !is_array($catalog['taxes']??null)) {
                throw new RuntimeException('Tax association catalog unavailable');
            }
            $found=false;
            foreach ($catalog['taxes'] as $tax) {
                if (!is_array($tax)) continue;
                if ($route==='api.nfse.settings.tax-associations.store'
                    && ($tax['id']??null)===($body['tax_id']??null)) $found=true;
                if ($route==='api.nfse.settings.tax-associations.revise') {
                    foreach ($tax['revisions']??[] as $revision) {
                        if (is_array($revision)
                            && ($revision['id']??null)===($params['associationId']??null)) {
                            $found=true;
                        }
                    }
                }
            }
            if (!$found) throw new RuntimeException('Tax or revision not owned by company');
            $snapshot['tax_catalog']=$catalog;
        } elseif ($route==='api.nfse.settings.approximate-taxes.store') {
            $snapshot['sources']=$reader->read($creds,$company,[
                'route'=>'api.nfse.settings.approximate-taxes.index',
                'nfse_filters'=>['page'=>1,'limit'=>50],
            ])['response'];
        } elseif ($route==='api.nfse.invoices.fiscal-event-evidence.store') {
            $invoiceId=$params['invoice']??null;
            if (!is_int($invoiceId)) throw new RuntimeException('Invoice ID required');
            $overview=$reader->read($creds,$company,[
                'route'=>'api.nfse.invoices.fiscal-assessment',
                'route_params'=>['invoice'=>$invoiceId],
            ])['response'];
            $found=false;
            foreach ($overview['data'] as $group) {
                if (is_array($group)
                    && ($group['group_key']??null)===($body['fiscal_group_key']??null)) {
                    $found=true;
                    break;
                }
            }
            if (!$found) throw new RuntimeException('Fiscal group is no longer current');
            $snapshot['fiscal_event_overview']=$overview;
        } elseif (str_starts_with($route,'api.nfse.invoices.')) {
            $sandbox=filter_var(
                $profile['fiscal_parameters']['sandbox_mode']??null,
                FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE
            );
            if ($sandbox===null) {
                throw new RuntimeException('Fiscal environment must be explicitly configured');
            }
            if (getenv('MCP_NFSE_FISCAL_WRITES_ENABLED')!=='true') {
                throw new RuntimeException('Fiscal MCP issue/cancel gate is disabled');
            }
            $invoiceId=$params['invoice']??null;
            if (!is_int($invoiceId)) throw new RuntimeException('Invoice ID required');
            $invoice=$api->get($creds['email'],$creds['password'],'/documents/'.$invoiceId,[
                'company_id'=>$company,'search'=>'type:invoice',
            ])['data']??null;
            if (!is_array($invoice) || array_is_list($invoice)
                || ($invoice['id']??null)!==$invoiceId
                || (int)($invoice['company_id']??0)!==$company
                || ($invoice['type']??null)!=='invoice') {
                throw new RuntimeException('Invoice does not belong to selected company');
            }
            $status=$reader->read($creds,$company,[
                'route'=>'api.nfse.invoices.post-emission-status',
                'route_params'=>['invoice'=>$invoiceId],
            ])['response']['data']??null;
            if (!is_array($status)) throw new RuntimeException('Fiscal status unavailable');
            $snapshot['invoice']=$invoice;
            $snapshot['fiscal_status']=$status;
            if (in_array($route,[
                'api.nfse.invoices.emit','api.nfse.invoices.reemit',
                'api.nfse.invoices.substitute',
            ],true)) {
                if ($route==='api.nfse.invoices.emit') {
                    if (($status['receipt_id']??null)!==null) {
                        throw new RuntimeException('Existing fiscal receipt requires explicit recovery or reemission');
                    }
                } else {
                    $substitute=$route==='api.nfse.invoices.substitute';
                    $receiptField=$substitute?'nfse_substitution_receipt_id':'nfse_receipt_id';
                    $requiredStatus=$substitute?'emitted':'cancelled';
                    if (($status['receipt_id']??null)!==($body[$receiptField]??null)) {
                        throw new RuntimeException('Fiscal receipt changed before proposed transition');
                    }
                    $number=$status['nfse_number']??null;
                    if (!is_string($number) || trim($number)==='' || strlen($number)>150) {
                        throw new RuntimeException('Current fiscal receipt number unavailable');
                    }
                    $ledger=$reader->read($creds,$company,[
                        'route'=>'api.nfse.ledger.index',
                        'nfse_filters'=>[
                            'search'=>$number,'status'=>$requiredStatus,'page'=>1,'limit'=>50,
                        ],
                    ])['response'];
                    $matching=array_values(array_filter($ledger['data']??[],
                        static fn (mixed $receipt): bool => is_array($receipt)
                            && ($receipt['id']??null)===$body[$receiptField]
                            && ($receipt['invoice_id']??null)===$invoiceId
                            && ($receipt['nfse_number']??null)===$number
                            && ($receipt['status']??null)===$requiredStatus
                    ));
                    if (count($matching)!==1) {
                        throw new RuntimeException('Current fiscal receipt state not confirmed');
                    }
                    $snapshot['referenced_receipt']=[
                        'id'=>$body[$receiptField],'invoice_id'=>$invoiceId,
                        'number'=>$number,'status'=>$requiredStatus,
                    ];
                }
                $readiness=$reader->read($creds,$company,[
                    'route'=>'api.nfse.dps.readiness',
                ])['response']['data']??null;
                $series=(string)($profile['fiscal_parameters']['dps_series']??'');
                if (preg_match('/^[0-9]{1,5}$/D',$series)!==1
                    || (int)$series<1 || (int)$series>49999) {
                    throw new RuntimeException('Fiscal DPS series is not configured');
                }
                $series=str_pad((string)(int)$series,5,'0',STR_PAD_LEFT);
                if (!is_array($readiness)
                    || ($readiness['mechanically_ready']??null)!==true
                    || ($readiness['company_id']??null)!==$company
                    || ($readiness['environment']??null)!==($sandbox?2:1)
                    || ($readiness['series']??null)!==$series) {
                    throw new RuntimeException('Fiscal DPS authority does not match the approved company, environment and series');
                }
                $preview=$reader->read($creds,$company,[
                    'route'=>'api.nfse.invoices.service-preview',
                    'route_params'=>['invoice'=>$invoiceId],
                ])['response'];
                if (($preview['fiscal_profile_validation']['isReady']??null)!==true) {
                    throw new RuntimeException('Invoice fiscal profile is not ready');
                }
                if (($preview['requires_split']??false)===true) {
                    if ($route!=='api.nfse.invoices.emit') {
                        throw new RuntimeException('Transition of split fiscal groups is unsupported');
                    }
                    $selected=$body['nfse_fiscal_group_key']??null;
                    $keys=array_column($preview['fiscal_groups']??[],'key');
                    if (!is_string($selected) || !in_array($selected,$keys,true)) {
                        throw new RuntimeException('Explicit fiscal group selection required');
                    }
                }
                $snapshot['dps_readiness']=$readiness;
                $snapshot['fiscal_preview']=$preview;
            } elseif (($status['receipt_id']??null)!==($body['nfse_receipt_id']??null)) {
                throw new RuntimeException('Fiscal receipt changed before cancellation');
            }
        } else {
            throw new RuntimeException('Unreviewed NFS-e mutation');
        }
        return hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    private function only(array $body, array $allowed): void
    {
        if (array_diff(array_keys($body),$allowed)!==[]) {
            throw new RuntimeException('Unapproved NFS-e mutation field');
        }
    }

    private function validGroupKey(mixed $value): bool
    {
        return is_string($value) && strlen($value)>0 && strlen($value)<=191
            && preg_match('/^[a-zA-Z0-9:_|.\-]+$/D',$value)===1;
    }

    private function validDate(mixed $value): bool
    {
        if (!is_string($value)) return false;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date!==false && $date->format('Y-m-d')===$value;
    }
}
