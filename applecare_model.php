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

    ];

    /**
     * Format date to month-day-year format (no time)
     * 
     * @param string|null $date Date string in Y-m-d H:i:s format
     * @return string|null Formatted date or null
     */
    public static function formatDate($date)
    {
        if (empty($date)) {
            return null;
        }
        
        try {
            $dt = new DateTime($date);
            return $dt->format('m-d-Y'); // e.g., "12-25-2024"
        } catch (\Exception $e) {
            return $date; // Return original if parsing fails
        }
    }

    /**
     * Check if a value is already formatted (m-d-Y format)
     */
    private function isFormattedDate($value)
    {
        return preg_match('/^\d{2}-\d{2}-\d{4}$/', $value);
    }

    /**
     * Accessor for formatted startDateTime (month-day-year)
     * Formats only when accessed for display, never modifies underlying attribute
     */
    public function getStartDateTimeAttribute($value)
    {
        // Always use raw value from attributes array to ensure we don't format already-formatted dates
        $rawValue = isset($this->attributes['startDateTime']) ? $this->attributes['startDateTime'] : $value;
        if (empty($rawValue)) {
            return null;
        }
        // Only format if it's in database format (Y-m-d), not if already formatted
        if (!$this->isFormattedDate($rawValue) && preg_match('/^\d{4}-\d{2}-\d{2}/', $rawValue)) {
            return self::formatDate($rawValue);
        }
        return $rawValue;
    }

    /**
     * Accessor for formatted endDateTime (month-day-year)
     * Formats only when accessed for display, never modifies underlying attribute
     */
    public function getEndDateTimeAttribute($value)
    {
        // Always use raw value from attributes array
        $rawValue = isset($this->attributes['endDateTime']) ? $this->attributes['endDateTime'] : $value;
        if (empty($rawValue)) {
            return null;
        }
        // Only format if it's in database format (Y-m-d), not if already formatted
        if (!$this->isFormattedDate($rawValue) && preg_match('/^\d{4}-\d{2}-\d{2}/', $rawValue)) {
            return self::formatDate($rawValue);
        }
        return $rawValue;
    }

    /**
     * Accessor for formatted contractCancelDateTime (month-day-year)
     * Formats only when accessed for display, never modifies underlying attribute
     */
    public function getContractCancelDateTimeAttribute($value)
    {
        // Always use raw value from attributes array
        $rawValue = isset($this->attributes['contractCancelDateTime']) ? $this->attributes['contractCancelDateTime'] : $value;
        if (empty($rawValue)) {
            return null;
        }
        // Only format if it's in database format (Y-m-d), not if already formatted
        if (!$this->isFormattedDate($rawValue) && preg_match('/^\d{4}-\d{2}-\d{2}/', $rawValue)) {
            return self::formatDate($rawValue);
        }
        return $rawValue;
    }

    /**
     * Accessor for formatted last_updated (month-day-year)
     * Formats only when accessed for display, never modifies underlying attribute
     */
    public function getLastUpdatedAttribute($value)
    {
        // Always use raw value from attributes array
        $rawValue = isset($this->attributes['last_updated']) ? $this->attributes['last_updated'] : $value;
        if (empty($rawValue)) {
            return null;
        }
        // Only format if it's in database format (Y-m-d), not if already formatted
        if (!$this->isFormattedDate($rawValue) && preg_match('/^\d{4}-\d{2}-\d{2}/', $rawValue)) {
            return self::formatDate($rawValue);
        }
        return $rawValue;
    }

    /**
     * Accessor for formatted isRenewable (True/False)
     * Converts 0/1 to False/True for display
     */
    public function getIsRenewableAttribute($value)
    {
        // Get raw value from attributes array
        $rawValue = isset($this->attributes['isRenewable']) ? $this->attributes['isRenewable'] : $value;
        // Convert 0/1 to False/True
        return (!empty($rawValue) && $rawValue != '0') ? 'True' : 'False';
    }

    /**
     * Accessor for formatted isCanceled (True/False)
     * Converts 0/1 to False/True for display
     */
    public function getIsCanceledAttribute($value)
    {
        // Get raw value from attributes array
        $rawValue = isset($this->attributes['isCanceled']) ? $this->attributes['isCanceled'] : $value;
        // Convert 0/1 to False/True
        return (!empty($rawValue) && $rawValue != '0') ? 'True' : 'False';
    }

    /**
     * Override getAttributes to format boolean and date fields for listings
     * MunkiReport listings may access attributes directly, so we format here
     */
    public function getAttributes()
    {
        $attributes = parent::getAttributes();
        
        // Format boolean fields (isRenewable, isCanceled) to True/False
        // This ensures listings show True/False even when accessing attributes directly
        $booleanFields = ['isRenewable', 'isCanceled'];
        foreach ($booleanFields as $field) {
            if (isset($attributes[$field])) {
                $rawValue = $attributes[$field];
                // Convert 0/1/true/false to False/True
                // Handle various formats: integer 0/1, string "0"/"1", boolean true/false
                if ($rawValue === 1 || $rawValue === '1' || $rawValue === true || 
                    $rawValue === 'true' || $rawValue === 'TRUE' || (is_string($rawValue) && strtolower(trim($rawValue)) === 'true')) {
                    $attributes[$field] = 'True';
                } else {
                    $attributes[$field] = 'False';
                }
            }
        }
        
        // Format date fields to month-day-year format
        $dateFields = ['startDateTime', 'endDateTime', 'contractCancelDateTime', 'last_updated'];
        foreach ($dateFields as $field) {
            if (isset($attributes[$field]) && !empty($attributes[$field])) {
                $rawValue = $attributes[$field];
                // Only format if it's in database format (Y-m-d)
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $rawValue)) {
                    $attributes[$field] = self::formatDate($rawValue);
                }
            }
        }
        
        return $attributes;
    }

    /**
     * Override toArray to ensure dates and booleans are formatted for display
     * This formats dates and booleans when model is serialized for listings and API responses
     */
    public function toArray()
    {
        $array = parent::toArray();
        
        // Format date fields using raw attribute values
        // Accessors handle formatting, but toArray might bypass them, so format explicitly
        $dateFields = ['startDateTime', 'endDateTime', 'contractCancelDateTime', 'last_updated'];
        foreach ($dateFields as $field) {
            if (isset($this->attributes[$field]) && !empty($this->attributes[$field])) {
                $rawValue = $this->attributes[$field];
                // Only format if it's in database format (Y-m-d)
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $rawValue)) {
                    $array[$field] = self::formatDate($rawValue);
                }
            }
        }
        
        // Format boolean fields (isRenewable, isCanceled) to True/False
        $booleanFields = ['isRenewable', 'isCanceled'];
        foreach ($booleanFields as $field) {
            if (isset($this->attributes[$field])) {
                $rawValue = $this->attributes[$field];
                $array[$field] = (!empty($rawValue) && $rawValue != '0') ? 'True' : 'False';
            }
        }
        
        return $array;
    }

    /**
     * Add custom filtering for status, expired, expiring, and inactive
     */
    public function scopeFilter($query, $filter = [])
    {
        // Get filter from request if not provided
        if (empty($filter)) {
            $filter = $_GET ?? [];
        }
        
        // Try to call parent filter first (MRModel may have its own filter logic)
        try {
            $parentClass = get_parent_class($this);
            if ($parentClass && method_exists($parentClass, 'scopeFilter')) {
                $query = parent::scopeFilter($query, $filter);
            }
        } catch (\Exception $e) {
            // Continue if parent filter doesn't exist or fails
        }
        
        // Handle status filter from query parameter
        if (isset($filter['status'])) {
            $status = strtoupper(trim($filter['status']));
            if ($status === 'ACTIVE') {
                $query->where('status', 'ACTIVE')
                      ->where(function($q) {
                          $q->whereNull('endDateTime')
                            ->orWhere('endDateTime', '>=', date('Y-m-d'));
                      });
            } elseif ($status === 'INACTIVE') {
                $query->where(function($q) {
                    $q->where('status', 'INACTIVE')
                      ->orWhereNull('status')
                      ->orWhere('status', '')
                      ->orWhere('isCanceled', true);
                });
            } else {
                $query->where('status', $status);
            }
        }
        
        // Handle expired filter
        if (isset($filter['expired']) && ($filter['expired'] == '1' || $filter['expired'] === true)) {
            $query->whereNotNull('endDateTime')
                  ->where('endDateTime', '<', date('Y-m-d'));
        }
        
        // Handle expiring filter (within 30 days)
        if (isset($filter['expiring']) && ($filter['expiring'] == '1' || $filter['expiring'] === true)) {
            $now = date('Y-m-d');
            $thirtyDays = date('Y-m-d', strtotime('+30 days'));
            $query->where('status', 'ACTIVE')
                  ->whereNotNull('endDateTime')
                  ->where('endDateTime', '>=', $now)
                  ->where('endDateTime', '<=', $thirtyDays);
        }
        
        return $query;
    }
}
