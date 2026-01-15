<?php

// Database seeder
// Please visit https://github.com/fzaninotto/Faker for more options

/** @var \Illuminate\Database\Eloquent\Factory $factory */
$factory->define(Applecare_model::class, function (Faker\Generator $faker) {

    return [
        'id' => $faker->unique()->bothify('H2WH10K#####'),
        'serial_number' => $faker->bothify('C02C#######'),
        'model' => $faker->randomElement(['MacBook Pro', 'iMac', 'Mac mini', 'iPad Pro']),
        'part_number' => $faker->bothify('Z1K#'),
        'product_family' => $faker->randomElement(['Mac', 'iPad']),
        'product_type' => $faker->word(),
        'color' => $faker->randomElement(['Space Gray', 'Silver', 'Gold']),
        'device_capacity' => $faker->randomElement(['256GB', '512GB', '1TB']),
        'device_assignment_status' => $faker->randomElement(['ASSIGNED', 'UNASSIGNED']),
        'purchase_source_type' => $faker->randomElement(['RESELLER', 'DIRECT']),
        'purchase_source_id' => $faker->bothify('1AE4C0'),
        'order_number' => $faker->bothify('ORDER-####'),
        'order_date' => $faker->dateTime(),
        'added_to_org_date' => $faker->dateTime(),
        'released_from_org_date' => null,
        'wifi_mac_address' => $faker->macAddress(),
        'ethernet_mac_address' => $faker->macAddress(),
        'bluetooth_mac_address' => $faker->macAddress(),
        'status' => $faker->randomElement(['ACTIVE', 'INACTIVE']),
        'paymentType' => $faker->randomElement(['PAID_UP_FRONT', 'SUBSCRIPTION', 'NONE']),
        'description' => $faker->sentence(),
        'startDateTime' => $faker->dateTime(),
        'endDateTime' => $faker->dateTime(),
        'isRenewable' => $faker->boolean(),
        'isCanceled' => $faker->boolean(),
        'contractCancelDateTime' => null,
        'agreementNumber' => $faker->bothify('AGREEMENT-####'),
        'last_updated' => $faker->unixTime(),
        'last_fetched' => $faker->unixTime(),
    ];
});
