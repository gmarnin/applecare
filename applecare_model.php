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
        'model',
        'part_number',
        'product_family',
        'product_type',
        'color',
        'device_capacity',
        'device_assignment_status',
        'purchase_source_type',
        'purchase_source_id',
        'order_number',
        'order_date',
        'added_to_org_date',
        'released_from_org_date',
        'wifi_mac_address',
        'ethernet_mac_address',
        'bluetooth_mac_address',
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
        'last_fetched',
        'sync_in_progress',
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
        'order_date' => 'datetime',
        'added_to_org_date' => 'datetime',
        'released_from_org_date' => 'datetime',
    ];

    /**
     * =====================================
     * Format dates as ISO for client-side formatting
     * =====================================
     * 
     * Returns dates in ISO format (Y-m-d) so moment.js can format
     * them according to user locale settings on the client side.
     */
    public function toArray()
    {
        $array = parent::toArray();

        $dateFields = [
            'startDateTime',
            'endDateTime',
            'contractCancelDateTime',
            'order_date',
            'added_to_org_date',
            'released_from_org_date',
            'last_updated',
            'last_fetched',
        ];

        foreach ($dateFields as $field) {
            if (!empty($array[$field])) {
                $array[$field] = self::formatDateToISO($array[$field]);
            }
        }

        return $array;
    }

    /**
     * =====================================
     * Date Formatter - Returns ISO format
     * =====================================
     *
     * Converts dates to ISO format (Y-m-d) for client-side formatting.
     * Moment.js will handle locale-aware formatting based on user settings.
     *
     * Converts:
     *  - ISO-8601 strings (with or without time)
     *  - Y-m-d / Y-m-d H:i:s
     *  - DateTime / Carbon
     *  - numeric timestamps
     *
     * Into: Y-m-d (ISO format for moment.js parsing)
     */
    private static function formatDateToISO($dateValue)
    {
        if (empty($dateValue)) {
            return null;
        }

        // DateTime / Carbon
        if ($dateValue instanceof \DateTime || (is_object($dateValue) && method_exists($dateValue, 'format'))) {
            return $dateValue->format('Y-m-d');
        }

        // Numeric timestamp
        if (is_numeric($dateValue)) {
            return date('Y-m-d', $dateValue);
        }

        // String - try to parse and return ISO format
        if (is_string($dateValue)) {
            // If already in Y-m-d format (with or without time), extract date part
            // Matches: YYYY-MM-DD, YYYY-MM-DD HH:MM:SS, YYYY-MM-DDTHH:MM:SS, etc.
            // Database stores as "2020-09-11 00:00:00" format
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateValue, $matches)) {
                return $matches[1]; // Return just the date part (YYYY-MM-DD)
            }
            
            // Try to parse and convert to ISO
            $timestamp = strtotime($dateValue);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        }

        return $dateValue;
    }
}