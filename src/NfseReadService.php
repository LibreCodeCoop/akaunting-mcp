<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Typed NFS-e API reads. Akaunting owns the fiscal domain, auth and tenancy.
 * No direct database access, duplicate rules, arbitrary URLs or fiscal POSTs.
 */
final class NfseReadService
{
    public function __construct(private readonly Api $api) {}

    /** @param array<string,mixed> $creds
     *  @param array<string,mixed> $args
     *  @return array<string,mixed>
     */
    public function read(array $creds, int $company, array $args): array
    {
        $name=$args['route'] ?? null;
        if (!is_string($name) || strlen($name)>120 || !str_starts_with($name,'api.nfse.')) {
            throw new RuntimeException('Missing NFS-e API route');
        }
        if (array_key_exists('fields',$args) || array_key_exists('record_type',$args)
            || array_key_exists('search',$args) || array_key_exists('page',$args)
            || array_key_exists('limit',$args) || array_key_exists('module_filters',$args)) {
            throw new RuntimeException('NFS-e reads require approved nfse_filters only');
        }
        $params=$args['route_params'] ?? [];
        $filters=$args['nfse_filters'] ?? [];
        if (!is_array($params) || !is_array($filters)) {
            throw new RuntimeException('Invalid NFS-e parameters');
        }

        $safePost=new NfseReadOnlyPostCatalog();
        if ($safePost->contains($name)) {
            if ($params!==[] || $filters!==[]) {
                throw new RuntimeException('Read-only POST accepts no route or query parameters');
            }
            $body=$args['post_body']??[];
            if (!is_array($body)) throw new RuntimeException('Invalid reviewed POST body');
            $safePost->validateBody($name,$body);
            $path=$safePost->resolve($name,[]);
            $result=$this->api->postReadOnly(
                $creds['email'],$creds['password'],$path,$company,$body
            );
            $value=$result['data']??null;
            if (!is_array($value)) throw new RuntimeException('Invalid read-only POST response');
            $this->validate($name,$company,$value);
            $safe=ReadResponseGuard::redact($value);
            if ($name==='api.nfse.settings.vault.diagnostics') {
                // Reviewed capability flags are strictly boolean, not certificate material.
                $safe['capabilities']=$value['capabilities'];
            }
            return [
                'route'=>$name,'company_id'=>$company,'response'=>$safe,
            ];
        }
        if (array_key_exists('post_body',$args)) {
            throw new RuntimeException('POST body is only for reviewed read-only NFS-e operations');
        }
        $catalog=new NfseReadCatalog();
        $resolved=$catalog->resolve($name,$params);
        $query=$catalog->query($name,$filters);
        $query['company_id']=$company;

        if (isset($params['invoice'])) {
            // Native invoices are ACL/type-sensitive. Verify ownership and
            // permitted invoice type before consulting fiscal child details.
            $invoice=$this->api->get(
                $creds['email'],$creds['password'],'/documents/'.$params['invoice'],
                ['company_id'=>$company,'search'=>'type:invoice'],
            );
            $document=$invoice['data'] ?? null;
            if (!is_array($document) || array_is_list($document)
                || (int)($document['company_id']??0)!==$company
                || ($document['type']??null)!=='invoice'
                || (int)($document['id']??0)!==$params['invoice']) {
                throw new RuntimeException('Cannot verify invoice ownership and type');
            }
            ReadResponseGuard::assertNestedCompany($document,$company);
        }

        $value=$this->api->get($creds['email'],$creds['password'],$resolved['path'],$query);
        $this->validate($name,$company,$value);
        $safe=ReadResponseGuard::redact($value);
        if ($name==='api.nfse.settings.artifacts.show') {
            // This reviewed field is a boolean presence flag, never a password.
            $safe['data']['password_configured']=$value['data']['password_configured'];
        }
        return [
            'route'=>$name,
            'company_id'=>$company,
            'response'=>$safe,
        ];
    }

    /** @param array<string,mixed> $response */
    private function validate(string $route, int $company, array $response): void
    {
        if ($route==='api.nfse.settings.vault.diagnostics') {
            $capabilities=$response['capabilities']??null;
            if (!in_array($response['state']??null,['operational','failed','unconfigured','unverified'],true)
                || !in_array($response['reason']??null,[
                    'verified','not_checked','missing_configuration',
                    'configuration_unreadable','invalid_endpoint','invalid_mount',
                    'server_unavailable','authentication_failed','kv_v2_unavailable',
                    'permission_check_failed','insufficient_capabilities','network_failure',
                ],true)
                || !is_array($capabilities) || array_is_list($capabilities)
                || count($capabilities)!==5) {
                throw new RuntimeException('Unsafe Vault diagnostic response');
            }
            foreach ($capabilities as $key=>$value) {
                if (!is_string($key)
                    || !in_array($key,['network','authentication','kv_v2','certificate_read','certificate_write'],true)
                    || !is_bool($value)) {
                    throw new RuntimeException('Unsafe Vault capability metadata');
                }
            }
        } elseif ($route==='api.nfse.bulk.plan') {
            $data=$response['data']??null;
            if (!is_array($data) || array_is_list($data)
                || array_diff(array_keys($data),[
                    'queued','blocked','already_issued','not_found_or_forbidden',
                ])!==[]) {
                throw new RuntimeException('Unsafe fiscal bulk preview');
            }
            foreach (['queued','blocked','already_issued','not_found_or_forbidden'] as $field) {
                if (!is_array($data[$field]??null) || !array_is_list($data[$field])) {
                    throw new RuntimeException('Malformed fiscal bulk preview');
                }
            }
        } elseif ($route==='api.nfse.dps.lookup') {
            $data=$response['data'] ?? null;
            if (!is_array($data) || !in_array($data['official_state'] ?? null,
                ['found','not_found','inconclusive'],true)
                || ($data['safe_to_emit'] ?? null)!==false
                || ($data['exclusive_series_attested'] ?? null)!==false) {
                throw new RuntimeException('Unsafe official DPS lookup response');
            }
        } elseif ($route==='api.nfse.dps.readiness') {
            $data=$response['data'] ?? null;
            if (!is_array($data) || !is_array($data['reasons'] ?? null)
                || ($data['external_reconciliation'] ?? null)!=='not_proven_by_this_endpoint') {
                throw new RuntimeException('Unsafe DPS readiness response');
            }
        } elseif ($route==='api.nfse.invoices.fiscal-assessment') {
            if (($response['scope']??null)!=='rtc_calendar_preview'
                || ($response['final_dps_checked']??null)!==false
                || !is_array($response['data']??null)
                || !array_is_list($response['data'])) {
                throw new RuntimeException('Unsafe fiscal event preview');
            }
        } elseif ($route==='api.nfse.invoices.service-preview') {
            if (!is_array($response['fiscal_groups'] ?? null)
                || !is_array($response['fiscal_profile_validation'] ?? null)) {
                throw new RuntimeException('Malformed fiscal preview');
            }
        } elseif ($route==='api.nfse.settings.company-profiles.index') {
            if (!is_array($response['profiles'] ?? null)
                || !is_array($response['eligible_companies'] ?? null)) {
                throw new RuntimeException('Malformed company profiles');
            }
        } elseif ($route==='api.nfse.settings.artifacts.show') {
            $data=$response['data'] ?? null;
            $allowed=[
                'configured','host','username_configured','password_configured',
                'path_template','path_scope','filename_template','store_xml','store_pdf',
            ];
            if (!is_array($data) || array_is_list($data)
                || array_diff(array_keys($data),$allowed)!==[]
                || !is_bool($data['configured'] ?? null)
                || !is_bool($data['username_configured'] ?? null)
                || !is_bool($data['password_configured'] ?? null)
                || !is_bool($data['store_xml'] ?? null)
                || !is_bool($data['store_pdf'] ?? null)
                || !is_string($data['path_template'] ?? null)
                || strlen($data['path_template'])>255
                || !in_array($data['path_scope']??null,['company','configured'],true)
                || !is_string($data['filename_template'] ?? null)
                || strlen($data['filename_template'])>255
                || !(($data['host'] ?? null)===null || (
                    is_string($data['host']) && strlen($data['host'])<=253
                    && preg_match('/[@\/\?#\x00-\x1F\x7F]/',$data['host'])!==1
                ))) {
                throw new RuntimeException('Unsafe WebDAV configuration response');
            }
        } elseif (!is_array($response['data'] ?? null)) {
            throw new RuntimeException('Missing fiscal API data');
        }
        ReadResponseGuard::assertNestedCompany($response,$company);
        $this->rejectEmbeddedArtifacts(
            $response,$route==='api.nfse.invoices.post-emission-status',
        );
    }

    private function rejectEmbeddedArtifacts(
        array $response, bool $allowXmlStatusMetadata=false,
        int $depth=0, string $parent='',
    ): void {
        if ($depth>12) throw new RuntimeException('Fiscal response too deeply nested');
        foreach ($response as $key=>$value) {
            if (is_string($key)
                && preg_match('/^(?:xml|authorized_xml|raw_xml|pfx|p12|private_key|pem)$/i',$key)) {
                $metadata=$allowXmlStatusMetadata && $key==='xml' && $parent==='artifacts'
                    && is_array($value) && !array_is_list($value)
                    && count($value)===2
                    && array_diff(array_keys($value),['ready','download_url'])===[]
                    && is_bool($value['ready'] ?? null)
                    && array_key_exists('download_url',$value)
                    && ($value['download_url']===null || (
                        is_string($value['download_url']) && strlen($value['download_url'])<=2048
                    ));
                if (!$metadata) throw new RuntimeException('Unexpected embedded fiscal artifact');
            }
            if (is_string($value) &&
                preg_match('/^\s*<\?xml\b|^\s*<(?:NFSe|DPS)\b/i',$value)) {
                throw new RuntimeException('Unexpected raw fiscal XML');
            }
            if (is_array($value)) {
                $this->rejectEmbeddedArtifacts(
                    $value,$allowXmlStatusMetadata,$depth+1,is_string($key)?$key:'',
                );
            }
        }
    }
}
