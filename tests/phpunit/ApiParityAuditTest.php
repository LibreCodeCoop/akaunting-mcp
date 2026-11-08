<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace AkauntingMcp\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../bin/check-api-parity.php';

final class ApiParityAuditTest extends TestCase
{
    public function testModuleParserExtractsNamedRoutesAndTheirActualVerbs(): void
    {
        $php = <<<'SOURCE'
            <?php
            Route::api('nfse', function(): void {
                Route::get('ledger', [Ledger::class, 'index'])
                    ->middleware('permission:read-sales-invoices')->name('ledger.index');
                Route::post('invoices/{invoice}/emit', [Invoices::class, 'emit'])
                    ->middleware('permission:update-sales-invoices')->name('invoices.emit');
                Route::delete('invoices/{invoice}', [Invoices::class, 'cancel'])
                    ->middleware('permission:update-sales-invoices')->name('invoices.cancel');
            });
            SOURCE;
        self::assertSame([
            ['name'=>'api.nfse.ledger.index','method'=>'GET','uri'=>'api/nfse/ledger'],
            ['name'=>'api.nfse.invoices.emit','method'=>'POST','uri'=>'api/nfse/invoices/{invoice}/emit'],
            ['name'=>'api.nfse.invoices.cancel','method'=>'DELETE','uri'=>'api/nfse/invoices/{invoice}'],
        ], \ApiParityAudit::fromModuleSource($php));
    }

    public function testParserFailsClosedOnUnrecognizedOrUnnamedRoutes(): void
    {
        $this->expectException(RuntimeException::class);
        \ApiParityAudit::fromModuleSource("<?php Route::get('ledger', [Ledger::class, 'index']);");
    }

    public function testKnownMissingOperationIsVisibleAndCannotBeMistakenForParity(): void
    {
        $report=\ApiParityAudit::compare([
            ['name'=>'api.nfse.ledger.index','method'=>'GET','uri'=>'api/nfse/ledger'],
            ['name'=>'api.nfse.invoices.emit','method'=>'POST','uri'=>'api/nfse/invoices/{invoice}/emit'],
        ], [
            ['route'=>'api.nfse.ledger.index','uri'=>'{company_id}/api/nfse/ledger'],
        ], [
            ['route'=>'api.nfse.invoices.emit', 'reason'=>'Fiscal emission contract is not yet exposed'],
        ]);
        self::assertSame(2,$report['total']);
        self::assertSame(1,$report['supported']);
        self::assertSame(['api.nfse.invoices.emit'],$report['pending']);
    }

    public function testNewMissingOperationFailsGate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unclassified');
        \ApiParityAudit::compare([
            ['name'=>'api.nfse.invoices.substitute','method'=>'POST','uri'=>'api/nfse/invoices/{invoice}/substitute'],
        ], [], []);
    }

    public function testContractMethodDriftCannotBeAllowlistedAsKnownGap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contract_drift');
        \ApiParityAudit::compare([
            ['name'=>'api.nfse.invoices.emit','method'=>'DELETE','uri'=>'api/nfse/invoices/{invoice}/emit'],
        ], [
            ['route'=>'api.nfse.invoices.emit','uri'=>'{company_id}/api/nfse/invoices/{invoice}/emit','methods'=>['POST']],
        ], []);
    }

    public function testStaleGapBaselinesFailAfterSupportIsImplemented(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale_baseline');
        \ApiParityAudit::compare([
            ['name'=>'api.nfse.ledger.index','method'=>'GET','uri'=>'api/nfse/ledger'],
        ], [
            ['route'=>'api.nfse.ledger.index','uri'=>'{company_id}/api/nfse/ledger'],
        ], [
            ['route'=>'api.nfse.ledger.index','reason'=>'Previously unimplemented fiscal ledger endpoint'],
        ]);
    }

    public function testAnnotatedReadAndCapabilityBootstrapAvoidMcpCatalogChurn(): void
    {
        $php = <<<'SOURCE'
            <?php
            Route::api('nfse', function (): void {
                Route::get('discovery', [Discover::class,'index'])->name('discovery.index');
                Route::get('invoices/{invoice}/fiscal-decision', [Decisions::class,'show'])
                    ->middleware('permission:read-sales-invoices')
                    ->defaults('mcp_read_contract', [
                        'version'=>1, 'scope'=>'invoice', 'response_paths'=>['/data/current'],
                    ])->name('invoices.fiscal-decision.show');
                Route::post('invoices/{invoice}/fiscal-decision', [Decisions::class,'store'])
                    ->middleware('permission:update-sales-invoices')
                    ->name('invoices.fiscal-decision.store');
            });
            SOURCE;
        $source=\ApiParityAudit::fromModuleSource($php);
        self::assertArrayNotHasKey('discovered', $source[0]);
        self::assertTrue($source[1]['discovered']);
        $report=\ApiParityAudit::compare($source, [], [
            ['route'=>'api.nfse.invoices.fiscal-decision.store',
                'reason'=>'Requires separately approved and validated fiscal mutation'],
        ]);
        self::assertSame(3,$report['total']);
        self::assertSame(2,$report['supported']);
        self::assertSame(['api.nfse.invoices.fiscal-decision.store'],$report['pending']);
    }

    public function testLaravelReadsTreatHeadAsPartOfGetAndPreserveWriteMethods(): void
    {
        self::assertSame([
            ['name'=>'api.contacts.index','method'=>'GET','uri'=>'api/contacts'],
            ['name'=>'api.contacts.update','method'=>'PUT','uri'=>'api/contacts/{contact}'],
            ['name'=>'api.contacts.update','method'=>'PATCH','uri'=>'api/contacts/{contact}'],
        ], \ApiParityAudit::fromLaravel([
            ['name'=>'api.contacts.index','method'=>'GET|HEAD','uri'=>'api/contacts'],
            ['name'=>'api.contacts.update','method'=>'PUT|PATCH','uri'=>'api/contacts/{contact}'],
            ['name'=>'web.ignored','method'=>'GET|HEAD','uri'=>'contacts'],
        ]));
    }
}
