<?php

namespace Modules\Billing\Services;

use Stripe\Stripe;

class StripePlanService
{
    public function createPlan(array $data): array
    {

        Stripe::setApiKey(config('services.stripe.secret'));


        $product = \Stripe\Product::create([
            'name' => $data['name'],
        ]);

        $price = \Stripe\Price::create([
            'unit_amount' => $data['price'] * 100,
            'currency' => $data['currency'],
            'recurring' => [
                'interval' => $data['interval'],
                'interval_count' => $data['interval_count'],
            ],
            'product' => $product->id,
        ]);

        return [
            'product_id' => $product->id,
            'price_id' => $price->id,
        ];
    }

    public function createPriceForExistingProduct(string $productId, array $data): array
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        $price = \Stripe\Price::create([
            'unit_amount' => (int) ($data['price'] * 100),
            'currency' => $data['currency'],
            'recurring' => [
                'interval' => $data['interval'],
                'interval_count' => $data['interval_count'],
            ],
            'product' => $productId,
        ]);

        return [
            'price_id' => $price->id,
        ];
    }

}
