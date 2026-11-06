<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Reviewed Field Studio read transport. Its native service validates permissions,
 * company ownership, record type, and per-record visibility; the MCP verifies
 * the response company again before returning redacted JSON.
 */
final class FieldStudioApi
{
    public function __construct(private readonly Api $api) {}

    /** @param array<string,mixed> $creds
     *  @param array<string,mixed> $args
     *  @return array<string,mixed>
     */
    public function read(array $creds,int $company,array $args): array
    {
        $name=$args['route']??null;
        if (!is_string($name) || !str_starts_with($name,'api.field-studio.')) {
            throw new RuntimeException('Field Studio route required');
        }
        foreach (['nfse_filters','post_body','fields','record_type','search','page','limit'] as $unapproved) {
            if (array_key_exists($unapproved,$args)) {
                throw new RuntimeException('Field Studio uses reviewed module_filters only');
            }
        }
        $params=$args['route_params']??[];
        $filters=$args['module_filters']??[];
        if (!is_array($params) || !is_array($filters)) {
            throw new RuntimeException('Invalid Field Studio arguments');
        }
        $catalog=new FieldStudioCatalog();
        $resolved=$catalog->resolveRead($name,$params);
        $query=$catalog->filters($name,$filters);
        $query['company_id']=$company;
        $value=$this->api->get(
            $creds['email'],$creds['password'],$resolved['path'],$query
        );
        $catalog->checkResponse($name,$company,$value,$params);
        return [
            'route'=>$name,'company_id'=>$company,
            'response'=>ReadResponseGuard::redact($value),
        ];
    }
}
