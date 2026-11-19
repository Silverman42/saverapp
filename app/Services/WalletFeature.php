<?php

namespace App\Services;

/**
 * The single gate for the Module 18 Customer wallet: config switches, the published business feature and a mapped wallet account.
 */
class WalletFeature
{
    public function __construct(private BusinessSettings $settings, private WalletBalanceService $wallets) {}

    public function active(): bool
    {
        return config('wallet.enabled') === true && config('wallet.certified') === true
            && $this->settings->featureEnabled('wallet') && $this->wallets->available();
    }

    public function collectionActive(): bool
    {
        return $this->active() && config('wallet.collection_enabled') === true && $this->settings->featureEnabled('collection_wallet');
    }

    public function ensureActive(): void
    {
        abort_unless($this->active(), 503, 'The Customer wallet is unavailable until its release gates pass.');
    }

    public function ensureCollectionActive(): void
    {
        abort_unless($this->collectionActive(), 503, 'Wallet contributions are unavailable until their release gates pass.');
    }
}
