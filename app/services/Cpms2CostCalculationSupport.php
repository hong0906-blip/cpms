<?php
// app/services/Cpms2CostCalculationSupport.php
// PHP 5.6. The rate precedence and nonpositive-unit fallback used by monthly_input/monthly_summary.
class Cpms2CostCalculationSupport
{
    public static function equipment($usage,$master)
    {
        $unit=isset($usage['work_unit']) && (float)$usage['work_unit']>0?(float)$usage['work_unit']:1.0;
        $snapshot=isset($usage['base_rate_snapshot'])?(float)$usage['base_rate_snapshot']:0.0;
        $masterRate=isset($master['base_rate'])?(float)$master['base_rate']:0.0;
        $rate=$snapshot>0?$snapshot:($masterRate>0?$masterRate:0.0);
        $raw=isset($usage['amount'])?(float)$usage['amount']:0.0;
        return array('quantity'=>sprintf('%.4f',$unit),'rate'=>sprintf('%.2f',$rate),'raw_amount'=>sprintf('%.2f',$raw),'canonical_amount'=>sprintf('%.2f',$rate>0?$unit*$rate:$raw),'calculation_basis'=>$rate>0?'work_unit_x_rate':'stored_amount');
    }
}
