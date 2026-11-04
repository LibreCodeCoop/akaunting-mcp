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
            || array_key_exists('limit',$args)) {
            throw new RuntimeException('NFS-e reads require approved nfse_filters only');
        }
        $params=$args['route_params'] ?? [];
        $filters=$args['nfse_filters'] ?? [];
        if (!is_array($params) || !is_array($filters)) {
            throw new RuntimeException('Invalid NFS-e parameters');
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
        return [
            'route'=>$name,
            'company_id'=>$company,
            'response'=>ReadResponseGuard::redact($value),
        ];
    }

    /** @param array<string,mixed> $response */
    private function validate(string $route, int $company, array $response): void
    {
        if ($route==='api.nfse.dps.lookup') {
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
        } elseif (!is_array($response['data'] ?? null)) {
            throw new RuntimeException('Missing fiscal API data');
        }
        ReadResponseGuard::assertNestedCompany($response,$company);
        $this->rejectEmbeddedArtifacts($response);
    }

    private function rejectEmbeddedArtifacts(array $response, int $depth=0): void
    {
        if ($depth>12) throw new RuntimeException('Fiscal response too deeply nested');
        foreach ($response as $key=>$value) {
            if (is_string($key) && preg_match('/^(?:xml|authorized_xml|raw_xml|pfx|p12|private_key|pem)$/i',$key)) {
                throw new RuntimeException('Unexpected embedded fiscal artifact');
            }
            if (is_string($value) &&
                preg_match('/^\s*<\?xml\b|^\s*<(?:NFSe|DPS)\b/i',$value)) {
                throw new RuntimeException('Unexpected raw fiscal XML');
            }
            if (is_array($value)) $this->rejectEmbeddedArtifacts($value,$depth+1);
        }
    }
}
