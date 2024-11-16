<?php

namespace App\Traits;

use App\Classes\Coinex2;
use App\Helpers\CoinexApi;
use App\Helpers\RequestHelper;
use App\Jobs\BuyOrder;
use App\Models\Api\v1\MongoTradeAmount;
use App\Models\Api\v1\OrdersList;
use App\Models\Api\v1\RequestType;
use Lin\Coinex\CoinexExchange;

Trait BuyExchangeTrait{

    public static function calculateOrderAmount($pair_amount, $currency_price)
    {
       // Remove commas from $pair_amount and $currency_price
       $pair_amount = str_replace(',', '', $pair_amount);
       $currency_price = str_replace(',', '', $currency_price);


        return ($pair_amount / $currency_price);
    }

    public static function calculateTotalTether($pair_amount, $currency_price = null, $taker_fee_rate, $static_percent)
    {
        // Remove commas from $pair_amount and $currency_price
        $pair_amount = str_replace(',', '', $pair_amount);
        $currency_price = str_replace(',', '', $currency_price);

        if ($currency_price) {
            $total_tether = ($currency_price * $pair_amount);
            $wage = (($static_percent / 100) * $total_tether);
            $taker_fee_rate = (($taker_fee_rate / 100) * $total_tether);
            return ($total_tether + $wage + $taker_fee_rate);
        } else {
            $wage = (($static_percent / 100) * $pair_amount);
            $taker_fee_rate = (($taker_fee_rate / 100) * $pair_amount);
            return ($pair_amount + $wage + $taker_fee_rate);
        }
    }

    public static function calculateWage($pair_amount, $currency_price = null, $static_percent)
    {
        // Remove commas from $pair_amount and $currency_price
        $pair_amount = str_replace(',', '', $pair_amount);
        $currency_price = str_replace(',', '', $currency_price);

        if ($currency_price) {
            $total_tether = ($currency_price * $pair_amount);
            return (($static_percent / 100) * $total_tether);
        } else {
            return (($static_percent / 100) * $pair_amount);
        }
    }

    public static function calculateNetworkWage($pair_amount, $currency_price = null, $taker_fee_rate)
    {
       // Remove commas from $pair_amount and $currency_price
       $pair_amount = str_replace(',', '', $pair_amount);
       $currency_price = str_replace(',', '', $currency_price);

        if ($currency_price) {

            $total_tether = ($currency_price * $pair_amount);
            return ($taker_fee_rate * $total_tether);

        } else
            return ($taker_fee_rate * $pair_amount);
    }

    /**
     * Format a number with decimal point.
     *
     * @param mixed $number The number being formatted
     * @param int $decimal The number of decimal points
     * @return string A formatted version of number
     */
    public static function numberFormat ($number, $decimal = 12)
    {
        return number_format($number, $decimal, '.', '');
    }
    /**
     * Calculate amount in toman
     */
    public static function calculateTomanAmount($total_tether, $tether_price)
    {
        return ($tether_price * $total_tether);
    }

    public static function coinexBuyOrder($coinex_order_info, $type, $extra_information = [])
    {

        if ($type == 'market') {

            $data = [
                'access_id' => $coinex_order_info['access_id'],
                'market' => $coinex_order_info['market'],
                'type' => $coinex_order_info['type'],
                'amount' => $coinex_order_info['amount'],
                'tonce' => $coinex_order_info['tonce'],
                'client_id' => $coinex_order_info['client_id'],
            ];

            $request_type = RequestType::get();
            switch ($request_type->en_name){
                case RequestType::EN_CLOUD:
                default:
                    $result = CoinexApi::send('order/market', $data , 'post');
                    break;
                case RequestType::EN_FOREIGN:
                    $result = RequestHelper::send('https://api.coinex.com/v1/order/market', 'post',  $data, $data);
                    break;
            }


            // Try 3 times
            $i = 0;
            while ($result->code != 0) {
                if ($i < 3)
                    break;
                switch ($request_type->en_name){
                    case RequestType::EN_CLOUD:
                        $result = CoinexApi::send('order/market', $data , 'post');
                        break;
                    case RequestType::EN_FOREIGN:
                        $result = RequestHelper::send('https://api.coinex.com/v1/order/market', 'post',  $data, $data);
                        break;
                }
                $i++;
            }

            if ($result->code != 0)
                return self::coinexErrorDetection($result->code);
            elseif ($result->code == 0)
                return $result->data;


        } else {

            // prepare data for request
            $data = [
                'access_id' => $coinex_order_info['access_id'],
                'market' => $coinex_order_info['market'],
                'type' => $coinex_order_info['type'],
                'amount' => $coinex_order_info['amount'],
                'price' => $coinex_order_info['price'],
                'account_id' => $coinex_order_info['account_id'],
                'tonce' => $coinex_order_info['tonce'],
                'client_id' => $coinex_order_info['client_id'],
            ];

            $request_type = RequestType::get();
            switch ($request_type->en_name){
                case RequestType::EN_CLOUD:
                default:
                    $result = CoinexApi::send('order/limit', $data , 'post');
                    break;
                case RequestType::EN_FOREIGN:
                    $result = RequestHelper::send('https://api.coinex.com/v1/order/limit', 'post',  $data, $data);
                    break;
            }

            // Try 3 times
            $i = 0;
            while ($result->code != 0) {
                if ($i < 3)
                    break;
                switch ($request_type->en_name){
                    case RequestType::EN_CLOUD:
                        $result = CoinexApi::send('order/limit', $data , 'post');
                        break;
                    case RequestType::EN_FOREIGN:
                        $result = RequestHelper::send('https://api.coinex.com/v1/order/limit', 'post',  $data, $data);
                        break;
                }
                $i++;
            }

            // Detect request result
            if ($result->code != 0)
                return self::coinexErrorDetection($result->code);
            elseif ($result->code == 0)
                return $result->data;

        }

    }

    public static function coinexErrorDetection($error_code): object
    {
        $error = null;
        switch ($error_code) {
            case 1:
                $error = __('coinex_error_one');
                break;
            case 2:
                $error = __('coinex_error_two');
                break;
            case 3:
                $error = __('coinex_error_three');
                break;
            case 23:
                $error = __('coinex_error_twenty_three');
                break;
            case 24:
                $error = __('coinex_error_twenty_four');
                break;
            case 25:
                $error = __('coinex_error_twenty_five');
                break;
            case 34:
                $error = __('coinex_error_thirty_four');
                break;
            case 35:
                $error = __('coinex_error_thirty_five');
                break;
            case 36:
                $error = __('coinex_error_thirty_six');
                break;
            case 40:
                $error = __('coinex_error_forty');
                break;
            case 49:
                $error = __('coinex_error_forty_nine');
                break;
            case 107:
                $error = __('coinex_error_one_hundred_and_seven');
                break;
            case 158:
                $error = __('coinex_error_one_hundred_and_fifty_eight');
                break;
            case 213:
                $error = __('coinex_error_two_hundred_and_thirteen');
                break;
            case 227:
                $error = __('coinex_error_two_hundred_and_twenty_seven');
                break;
            case 600:
                $error = __('coinex_error_six_hundred');
                break;
            case 601:
                $error = __('coinex_error_six_hundred_and_one');
                break;
            case 602:
                $error = __('coinex_error_six_hundred_and_two');
                break;
            case 606:
                $error = __('coinex_error_six_hundred_and_six');
                break;
            case 651:
                $error = __('coinex_error_six_hundred_and_fifty_one');
                break;
            case 3008:
                $error = __('coinex_error_three_thousand_eight');
                break;
        }
        return (object)[
            'code' => $error_code,
            'error' => $error,
        ];
    }

    public static function calculateStaticPercentForTether($user_id)
    {

        $past_thirty_days = MongoTradeAmount::pastThirtyDays($user_id);

        // Rules about user trade amount
        switch (true) {
            case ($past_thirty_days >= 0 && $past_thirty_days <= 100000000):
                return 0.25;
            case ($past_thirty_days > 100000000 && $past_thirty_days <= 500000000):
                return 0.22;
            case ($past_thirty_days > 500000000 && $past_thirty_days <= 1000000000):
                return 0.2;
            case ($past_thirty_days > 1000000000 && $past_thirty_days <= 3000000000):
                return 0.175;
            case ($past_thirty_days > 3000000000):
                return 0.15;
        }

    }

    public static function detectPaymentType($amount_type, $pair_type, $is_usdt_wallet, $is_irr_wallet): string
    {
        $amount_type = strtolower($amount_type);

        switch ($amount_type) {
            case 'currency':
                if ($is_usdt_wallet) return OrdersList::USDT_PAYMENT;
                else if ($is_irr_wallet) return OrdersList::IRR_PAYMENT;

                return $pair_type;
            case 'usdt':
                return OrdersList::USDT_PAYMENT;
            case 'toman':
            default:
                return OrdersList::IRR_PAYMENT;
        }

    }

    public static function detectReceiptType($receipt_type)
    {
        $receipt_type = strtolower($receipt_type);

        switch ($receipt_type) {
            case 'usdt':
                return OrdersList::USDT_PAYMENT;
            case 'toman':
            default:
                return OrdersList::IRR_PAYMENT;
        }
    }

    public static function calculatePairAmount($data_provided)
    {
        switch ($data_provided['amount_type']) {
            case 'currency':
            case 'usdt':
                return $data_provided['pair_amount'];
            case 'toman':
            default:
                return ($data_provided['pair_amount'] / $data_provided['tether_price']);
        }
    }

    public static function provideMinAmountResponse($data_provided)
    {

        switch ($data_provided['amount_type']) {

            case 'currency':
                return [
                    'required_min_amount' => $data_provided['min_amount'],
                    'your_order_amount' => $data_provided['order_amount'],
                    'required_amount' => BuyExchangeTrait::numberFormat(($data_provided['min_amount'] - $data_provided['order_amount'])),
                    'amount_type' => 'currency',
                ];
            case 'toman':

                    $min_amount_toman = ($data_provided['min_amount'] * $data_provided['info_last']) * $data_provided['tether_price'];
                    $total_min_amount_toman = $min_amount_toman + $data_provided['order_total_fee_irt'] ;

                return [
                    'required_min_amount' => $data_provided['min_amount'],
                    'your_order_amount' => $data_provided['order_amount'],
                    'required_amount' => ceil(BuyExchangeTrait::numberFormat(( $total_min_amount_toman - $data_provided['total_toman'] ))),
                    'amount_type' => 'toman',
                ];
            case 'usdt':
                    $min_amount_usdt = $data_provided['min_amount'] * $data_provided['info_last'];
                    $total_min_amount_usdt = $min_amount_usdt + $data_provided['order_total_fee_usdt'];

                return [
                    'required_min_amount' => $data_provided['min_amount'],
                    'your_order_amount' => $data_provided['order_amount'],
                    'required_amount' => Coinex2::roundUpToDecimal(BuyExchangeTrait::numberFormat($total_min_amount_usdt - $data_provided['total_tether'])),
                    'amount_type' => 'usdt',
                ];

        }


    }

    public static function kucoinBuyOrder()
    {

    }

    public static function kucoinErrorDetection()
    {

    }

}
