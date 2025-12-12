<?php

// Database seeder
// Please visit https://github.com/fzaninotto/Faker for more options

/** @var \Illuminate\Database\Eloquent\Factory $factory */
$factory->define(Applecare_model::class, function (Faker\Generator $faker) {

    return [
        'status' => $faker->word(),
        'paymentType' => $faker->word(),
        'description' => $faker->word(),
        'startDateTime' => $faker->word(),
        'endDateTime' => $faker->word(),
        'isRenewable' => $faker->word(),
        'isCanceled' => $faker->word(),
        'contractCancelDateTime' => $faker->word(),
        'agreementNumber' => $faker->randomNumber($nbDigits = 4, $strict = false),
    ];
});
