<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace AkauntingMcp;

use RuntimeException;

/**
 * Browser handoff for native operations whose secrets, files or raw financial
 * evidence must not transit an LLM-readable MCP JSON result.
 *
 * The URL is not a bearer credential. Akaunting still enforces its own
 * authenticated browser session, company membership, CSRF and route ACL.
 */
final class NfseSecureHandoff
{
    private const OPERATIONS = [
        'api.nfse.certificates.inspect' => [
            'path'=>'settings?tab=company-profiles',
            'description'=>'Inspect a PFX using the native, authenticated form',
        ],
        'api.nfse.settings.company-profiles.store' => [
            'path'=>'settings?tab=company-profiles',
            'description'=>'Create a fiscal profile through native PFX upload',
        ],
        'api.nfse.settings.vault.update' => [
            'path'=>'settings?tab=vault',
            'description'=>'Enter Vault credentials in Akaunting, not in chat',
        ],
        'api.nfse.closing.export' => [
            'path'=>'closing',
            'description'=>'Export the authenticated closing CSV from Akaunting',
        ],
        'api.nfse.closing.ibs-cbs-evidence' => [
            'path'=>'closing',
            'description'=>'Export documentary IBS/CBS evidence in Akaunting',
        ],
        'api.nfse.receipts.artifacts.download' => [
            'path'=>'ledger',
            'description'=>'Download the authorized NFS-e XML/DANFSe in Akaunting',
        ],
        'api.nfse.invoices.emails.store' => [
            'path'=>'invoices',
            'description'=>'Open the native invoice email composer. Sending may trigger fiscal issuance and requires explicit human action.',
        ],
    ];

    public function __construct(private readonly Api $api) {}

    /** @param array<string,string> $credentials
     *  @param array<string,mixed> $params
     *  @return array<string,mixed>
     */
    public function link(array $credentials, int $companyId, string $operation, array $params): array
    {
        Api::companyId($companyId);
        if (!isset(self::OPERATIONS[$operation])) {
            throw new RuntimeException('Unreviewed NFS-e browser handoff');
        }
        $this->api->verify($credentials['email'], $credentials['password'], $companyId);
        $path=self::OPERATIONS[$operation]['path'];
        $query=['company_id'=>$companyId];
        $keys=[];
        if (in_array($operation,[
            'api.nfse.certificates.inspect',
            'api.nfse.settings.company-profiles.store',
            'api.nfse.settings.vault.update',
        ],true)) {
            $this->api->get(
                $credentials['email'],$credentials['password'],
                '/nfse/settings/company-profiles',$query,
            );
        } elseif (in_array($operation,[
            'api.nfse.closing.export',
            'api.nfse.closing.ibs-cbs-evidence',
        ],true)) {
            $keys=['competence'];
            $competence=$params['competence']??null;
            if (!is_string($competence)
                || preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$competence)!==1) {
                throw new RuntimeException('Explicit valid fiscal competence required');
            }
            $result=$this->api->get(
                $credentials['email'],$credentials['password'],'/nfse/closing',
                [...$query,'competence'=>$competence,'report_page'=>1],
            );
            if (!is_array($result['data']??null)
                || ($result['data']['competence']??null)!==$competence) {
                throw new RuntimeException('Unable to authorize fiscal closing');
            }
            $path.='?competence='.rawurlencode($competence);
        } elseif ($operation==='api.nfse.invoices.emails.store') {
            $keys=['invoice'];
            $invoiceId=$params['invoice']??null;
            if (!is_int($invoiceId) || $invoiceId<1 || $invoiceId>2147483647) {
                throw new RuntimeException('Email handoff requires an existing company invoice');
            }
            $invoice=$this->api->get(
                $credentials['email'],$credentials['password'],
                '/documents/'.$invoiceId,[...$query,'search'=>'type:invoice'],
            )['data']??null;
            if (!is_array($invoice) || array_is_list($invoice)
                || ($invoice['id']??null)!==$invoiceId
                || ($invoice['company_id']??null)!==$companyId
                || ($invoice['type']??null)!=='invoice') {
                throw new RuntimeException('Email target invoice does not belong to selected company');
            }
            ReadResponseGuard::assertNestedCompany($invoice,$companyId);
            $path='invoices/'.$invoiceId;
        } else {
            $keys=['invoice','receipt','artifact'];
            $invoiceId=$params['invoice']??null;
            $receiptId=$params['receipt']??null;
            $artifact=$params['artifact']??null;
            if (!is_int($invoiceId) || $invoiceId<1 || !is_int($receiptId)
                || $receiptId<1 || !in_array($artifact,['xml','danfse'],true)) {
                throw new RuntimeException('A specific authorized fiscal artifact is required');
            }
            $invoice=$this->api->get(
                $credentials['email'],$credentials['password'],
                '/documents/'.$invoiceId,[...$query,'search'=>'type:invoice'],
            )['data']??null;
            if (!is_array($invoice) || array_is_list($invoice)
                || ($invoice['id']??null)!==$invoiceId
                || ($invoice['company_id']??null)!==$companyId
                || ($invoice['type']??null)!=='invoice') {
                throw new RuntimeException('Invoice does not belong to selected company');
            }
            ReadResponseGuard::assertNestedCompany($invoice,$companyId);
            $status=$this->api->get(
                $credentials['email'],$credentials['password'],
                '/nfse/invoices/'.$invoiceId.'/post-emission-status',$query,
            )['data']??null;
            if (!is_array($status)
                || ($status['receipt_id']??null)!==$receiptId
                || ($status['artifacts'][$artifact]['ready']??null)!==true) {
                throw new RuntimeException('Receipt artifact is not ready or no longer current');
            }
            $path='ledger/'.$receiptId.'/artifacts/'.$artifact;
        }
        if (array_diff(array_keys($params),$keys)!==[]
            || array_diff($keys,array_keys($params))!==[]) {
            throw new RuntimeException('Unexpected NFS-e handoff parameters');
        }
        $base=$this->api->applicationBaseUrl();
        if (filter_var($base,FILTER_VALIDATE_URL)===false
            || parse_url($base,PHP_URL_SCHEME)!=='https'
            || parse_url($base,PHP_URL_USER)!==null
            || parse_url($base,PHP_URL_PASS)!==null
            || parse_url($base,PHP_URL_QUERY)!==null
            || parse_url($base,PHP_URL_FRAGMENT)!==null) {
            throw new RuntimeException('Browser application origin is not safe for handoff');
        }

        return [
            'route'=>$operation,'company_id'=>$companyId,
            'delivery'=>'native_authenticated_browser',
            'url'=>rtrim($base,'/').'/'.$companyId.'/nfse/'.$path,
            'description'=>self::OPERATIONS[$operation]['description'],
            'requires_separate_browser_authentication'=>true,
            'transfers_credentials_or_file_contents_to_mcp'=>false,
            'note'=>'This is navigation to a native functionality, not an MCP execution, API result or authorization. Browser permissions and human interaction remain mandatory.',
        ];
    }

    /** @return list<string> */
    public static function operations(): array
    {
        return array_keys(self::OPERATIONS);
    }
}
