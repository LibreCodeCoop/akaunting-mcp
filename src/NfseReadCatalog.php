<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use DateTimeImmutable;
use RuntimeException;

/**
 * Reviewed NFS-e JSON GET contracts from LibreCodeCoop/akaunting-nfse.
 * The module supplies the API; this is only a typed, restrictive client.
 * Binary downloads and government-backed ADN distribution are deliberately
 * excluded from JSON browsing until they have separate transport reviews.
 */
final class NfseReadCatalog
{
    /** @var array<string,array{path:string,permission:string,filters:list<string>}> */
    private const ROUTES = [
        'api.nfse.dps.readiness' => ['path'=>'/nfse/dps/readiness', 'permission'=>'read-nfse-settings', 'filters'=>[]],
        'api.nfse.dps.lookup' => ['path'=>'/nfse/dps/lookup', 'permission'=>'read-nfse-settings', 'filters'=>['number']],
        'api.nfse.national-services.index' => [
            'path'=>'/nfse/national-services', 'permission'=>'read-nfse-settings',
            'filters'=>['lc116','q','limit'],
        ],
        'api.nfse.invoices.fiscal-assessment' => [
            'path'=>'/nfse/invoices/{invoice}/fiscal-assessment',
            'permission'=>'read-sales-invoices', 'filters'=>[],
        ],
        'api.nfse.invoices.service-preview' => [
            'path'=>'/nfse/invoices/{invoice}/service-preview', 'permission'=>'read-sales-invoices', 'filters'=>[],
        ],
        'api.nfse.invoices.post-emission-status' => [
            'path'=>'/nfse/invoices/{invoice}/post-emission-status', 'permission'=>'read-sales-invoices', 'filters'=>[],
        ],
        'api.nfse.invoices.emission-attempts.index' => [
            'path'=>'/nfse/invoices/{invoice}/emission-attempts', 'permission'=>'read-sales-invoices', 'filters'=>[],
        ],
        'api.nfse.invoices.adn-events' => [
            'path'=>'/nfse/invoices/{invoice}/adn-events', 'permission'=>'read-sales-invoices', 'filters'=>[],
        ],
        'api.nfse.ledger.index' => [
            'path'=>'/nfse/ledger', 'permission'=>'read-sales-invoices',
            'filters'=>['page','limit','status','search','from','to'],
        ],
        'api.nfse.closing.index' => [
            'path'=>'/nfse/closing', 'permission'=>'read-sales-invoices',
            'filters'=>['competence','page','report_page'],
        ],
        'api.nfse.bulk.runs' => [
            'path'=>'/nfse/bulk/runs', 'permission'=>'read-sales-invoices', 'filters'=>['page'],
        ],
        'api.nfse.bulk.runs.show' => [
            'path'=>'/nfse/bulk/runs/{run}', 'permission'=>'read-sales-invoices', 'filters'=>[],
        ],
        'api.nfse.adn.review-options' => [
            'path'=>'/nfse/adn/review-options', 'permission'=>'read-purchases-bills', 'filters'=>[],
        ],
        'api.nfse.adn.documents.index' => [
            'path'=>'/nfse/adn/documents', 'permission'=>'read-purchases-bills',
            'filters'=>['page','limit','review_status','fiscal_role','include_preview'],
        ],
        'api.nfse.adn.documents.review-history' => [
            'path'=>'/nfse/adn/documents/{document}/review-history',
            'permission'=>'read-purchases-bills','filters'=>['page','limit'],
        ],
        'api.nfse.adn.documents.preview' => [
            'path'=>'/nfse/adn/documents/{document}/preview',
            'permission'=>'read-purchases-bills','filters'=>[],
        ],
        'api.nfse.settings.company-profiles.index' => [
            'path'=>'/nfse/settings/company-profiles', 'permission'=>'read-nfse-settings','filters'=>[],
        ],
        'api.nfse.settings.tax-associations.index' => [
            'path'=>'/nfse/settings/tax-associations', 'permission'=>'read-nfse-settings','filters'=>[],
        ],
        'api.nfse.settings.artifacts.show' => [
            'path'=>'/nfse/settings/artifacts', 'permission'=>'read-nfse-settings','filters'=>[],
        ],
        'api.nfse.settings.category-issuance.index' => [
            'path'=>'/nfse/settings/category-issuance',
            'permission'=>'read-nfse-settings', 'filters'=>[],
        ],
        'api.nfse.settings.approximate-taxes.index' => [
            'path'=>'/nfse/settings/approximate-taxes', 'permission'=>'read-nfse-settings',
            'filters'=>['page','limit'],
        ],
        'api.nfse.settings.vault.show' => [
            'path'=>'/nfse/settings/vault', 'permission'=>'read-nfse-settings','filters'=>[],
        ],
        // #497: additional reviewed native resources; data is owned by Akaunting.
        'api.nfse.dashboard.index' => [
            'path'=>'/nfse/dashboard', 'permission'=>'read-sales-invoices', 'filters'=>[],
        ],
        'api.nfse.settings.email-footer.show' => [
            'path'=>'/nfse/settings/email-footer', 'permission'=>'read-nfse-settings', 'filters'=>[],
        ],
        'api.nfse.invoices.email-preferences.show' => [
            'path'=>'/nfse/invoices/{invoice}/email-preferences',
            'permission'=>'read-sales-invoices', 'filters'=>[],
        ],
        'api.nfse.ibge.ufs' => [
            'path'=>'/nfse/ibge/ufs', 'permission'=>'read-nfse-settings', 'filters'=>[],
        ],
        'api.nfse.ibge.municipalities' => [
            'path'=>'/nfse/ibge/municipalities/{uf}', 'permission'=>'read-nfse-settings', 'filters'=>[],
        ],
        'api.nfse.lc116.services' => [
            'path'=>'/nfse/lc116/services', 'permission'=>'read-nfse-settings', 'filters'=>['q','limit'],
        ],
        // Not registered: municipal-parameters GET writes an advisory cache.
    ];

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $items = [];
        foreach (self::ROUTES as $name => $spec) {
            preg_match_all('/\{([a-z_]+)\}/', $spec['path'], $matches);
            $items[] = [
                'route'=>$name,
                'uri'=>'{company_id}/api'. $spec['path'],
                'source'=>'module',
                'parameters'=>$matches[1],
                'required_permission_hints'=>[$spec['permission']],
                'filters'=>$spec['filters'],
            ];
        }
        return $items;
    }

    /** @return array{path:string,route:array<string,mixed>} */
    public function resolve(string $name, array $params): array
    {
        $spec=self::ROUTES[$name] ?? null;
        if ($spec===null) throw new RuntimeException('Unreviewed NFS-e route');
        preg_match_all('/\{([a-z_]+)\}/', $spec['path'], $matches);
        $expected=$matches[1];
        if (count($params)!==count($expected)
            || array_diff($expected,array_keys($params))!==[]
            || array_diff(array_keys($params),$expected)!==[]) {
            throw new RuntimeException('Invalid NFS-e route parameters');
        }
        $path=$spec['path'];
        foreach ($expected as $key) {
            $value=$params[$key];
            if ($key==='uf') {
                if (!is_string($value) || preg_match('/^[A-Z]{2}$/D',$value)!==1) {
                    throw new RuntimeException('Invalid UF for municipal listing');
                }
            } elseif (!is_int($value) || $value<=0 || $value>2147483647) {
                throw new RuntimeException('NFS-e path IDs must be positive integers');
            }
            $path=str_replace('{'.$key.'}',(string)$value,$path);
        }
        return ['path'=>$path,'route'=>$spec];
    }

    public function permitsPath(string $path): bool
    {
        foreach (self::ROUTES as $spec) {
            $parts=preg_split('/(\{[a-z_]+\})/', $spec['path'],-1,PREG_SPLIT_DELIM_CAPTURE);
            $regex='';
            foreach ($parts as $part) {
                if ($part==='{uf}') {
                    $regex.='[A-Z]{2}';
                } else {
                    $regex.=preg_match('/^\{[a-z_]+\}$/D',$part)
                        ? '[1-9][0-9]{0,9}' : preg_quote($part,'~');
                }
            }
            if (preg_match('~^'.$regex.'$~D',$path)===1) return true;
        }
        return false;
    }

    /** @param array<array-key,mixed> $input
     *  @return array<string,int|string>
     */
    public function query(string $name, array $input): array
    {
        $spec=self::ROUTES[$name] ?? null;
        if ($spec===null) throw new RuntimeException('Unreviewed NFS-e route');
        if (array_is_list($input) && $input!==[]) throw new RuntimeException('Invalid NFS-e filters');
        $out=[];
        foreach ($input as $key=>$value) {
            if (!is_string($key) || !in_array($key,$spec['filters'],true)) {
                throw new RuntimeException('Unapproved NFS-e filter');
            }
            if (in_array($key,['page','limit','report_page'],true)) {
                if (!is_int($value) || $value<1 || $value>($key==='limit'?50:10000)) {
                    throw new RuntimeException('Invalid NFS-e pagination');
                }
                $out[$key]=$value;
                continue;
            }
            if ($key==='include_preview') {
                if (!in_array($value,[0,1],true)) throw new RuntimeException('Invalid preview option');
                $out[$key]=$value;
                continue;
            }
            if (!is_string($value) || strlen($value)>200 || preg_match('/[\x00-\x1F\x7F]/',$value)) {
                throw new RuntimeException('Invalid NFS-e filter value');
            }
            $ok=match($key) {
                'competence'=>preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$value)===1,
                'from','to'=>$this->validDate($value),
                'lc116'=>preg_match('/^\d{4}$/D',$value)===1,
                'number'=>preg_match('/^[1-9][0-9]{0,14}$/D',$value)===1,
                'q'=>strlen($value)<=100,
                'status'=>in_array($value,['all','emitted','processing','cancelled','substituted'],true),
                'review_status'=>in_array($value,['pending','imported','ignored','not_applicable'],true),
                'fiscal_role'=>in_array($value,['emitted','received','intermediated','event','unknown'],true),
                default=>true,
            };
            if (!$ok) throw new RuntimeException('Invalid NFS-e filter value');
            $out[$key]=$value;
        }
        return $out;
    }

    private function validDate(string $value): bool
    {
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $dt!==false && $dt->format('Y-m-d')===$value;
    }
}
