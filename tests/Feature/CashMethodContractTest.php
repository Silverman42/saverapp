<?php

use App\Models\CashMethodVersion;
use App\Services\AuditProjection;
use App\Services\CashMethodCatalogue;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

test('cash contract validates durable historical versions independently of current configuration', function (): void {
    config()->set('withdrawals.cash_method_version', 99);
    $catalogue = app(CashMethodCatalogue::class);
    expect($catalogue->version('withdrawal', 1))->toBe(1)
        ->and($catalogue->version('fee_refund', 1))->toBe(1)
        ->and($catalogue->version('earnings_draw', 1))->toBe(1);
    expect(fn () => $catalogue->version('withdrawal'))->toThrow(ConflictHttpException::class);
});

test('published cash method contracts cannot be edited or removed', function (): void {
    $method = CashMethodVersion::query()->where('method_key', 'cash')->where('version', 1)->sole();
    expect(fn () => $method->update(['contract_hash' => str_repeat('a', 64)]))->toThrow(RuntimeException::class);
    expect(fn () => $method->delete())->toThrow(RuntimeException::class);
});

test('cash contract integrity rejects changed recipient rules even with a matching replacement hash', function (): void {
    $contract = CashMethodCatalogue::VERSION_ONE;
    $contract['recipient']['withdrawal'] = 'third_party';
    DB::table('cash_method_versions')->where('method_key', 'cash')->where('version', 1)->update(['contract' => json_encode($contract, JSON_THROW_ON_ERROR),
        'contract_hash' => AuditProjection::digest($contract)]);
    expect(fn () => app(CashMethodCatalogue::class)->version('withdrawal', 1))->toThrow(ConflictHttpException::class);
});

test('unimplemented method versions never gain authority from a stored catalogue row', function (): void {
    $method = CashMethodVersion::factory()->create();
    expect(fn () => app(CashMethodCatalogue::class)->version('withdrawal', $method->version))->toThrow(ConflictHttpException::class);
});
