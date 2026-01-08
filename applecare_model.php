<?php

use munkireport\models\MRModel as Eloquent;
use Illuminate\Database\Eloquent\Builder;

class Applecare_model extends Eloquent
{
    protected $table = 'applecare';

    protected $hidden = ['id', 'serial_number'];

    protected $fillable = [
        'id',
        'serial_number',
        'status',
        'paymentType',
        'description',
        'startDateTime',
        'endDateTime',
        'isRenewable',
        'isCanceled',
        'contractCancelDateTime',
        'agreementNumber',
        'last_updated',
    ];

    /**
     * Keep casts for correct sync behavior
     */
    protected $casts = [
        'isRenewable' => 'boolean',
        'isCanceled'  => 'boolean',
        'startDateTime' => 'datetime',
        'endDateTime' => 'datetime',
        'contractCancelDateTime' => 'datetime',
    ];

    /**
     * =====================================
     * Force display formatting for API/UI
     * =====================================
     */
    public function toArray()
    {
        $array = parent::toArray();

        $dateFields = [
            'startDateTime',
            'endDateTime',
            'contractCancelDateTime',
            'last_updated',
        ];

        foreach ($dateFields as $field) {
            if (!empty($array[$field])) {
                $array[$field] = self::formatDate($array[$field]);
            }
        }

        return $array;
    }

    /**
     * =====================================
     * Date Formatter (single source of truth)
     * =====================================
     *
     * Converts:
     *  - ISO-8601 strings
     *  - Y-m-d / Y-m-d H:i:s
     *  - DateTime / Carbon
     *  - numeric timestamps
     *
     * Into: MM-DD-YYYY
     */
    private static function formatDate($dateValue)
    {
        if (empty($dateValue)) {
            return null;
        }

        // DateTime / Carbon
        if ($dateValue instanceof \DateTime || (is_object($dateValue) && method_exists($dateValue, 'format'))) {
            return $dateValue->format('m-d-Y');
        }

        // Numeric timestamp
        if (is_numeric($dateValue)) {
            return date('m-d-Y', $dateValue);
        }

        // String (ISO-8601, Y-m-d, etc.)
        if (is_string($dateValue)) {
            $timestamp = strtotime($dateValue);
            if ($timestamp !== false) {
                return date('m-d-Y', $timestamp);
            }
        }

        return $dateValue;
    }
}