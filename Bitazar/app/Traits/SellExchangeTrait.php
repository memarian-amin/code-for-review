<?php


namespace App\Traits;

use App\Helpers\CoinexApi;
use App\Helpers\RequestHelper;
use App\Jobs\SellOrder;
use App\Models\Api\v1\RequestType;
use Lin\Coinex\CoinexExchange;

Trait SellExchangeTrait{

    public static function calculateTotalTether($pair_amount, $currency_price = null, $maker_fee_rate, $static_percent)
    {
        $total_tether = $currency_price * $pair_amount;
        $wage = ($static_percent / 100) * $total_tether;
        $maker_fee_rate = ($maker_fee_rate / 100) * $total_tether;
        return $total_tether - $wage - $maker_fee_rate;
    }

    public static function calculateWage($pair_amount, $currency_price = null, $static_percent)
    {
        $total_tether = $currency_price * $pair_amount;
        return ($static_percent / 100) * $total_tether;

    }

    public static function calculateNetworkWage($pair_amount, $currency_price = null, $maker_fee_rate)
    {
        if ($currency_price) {

            $total_tether = $currency_price * $pair_amount;
            return ($maker_fee_rate / 100) * $total_tether;

        } else
            return ($maker_fee_rate / 100) * $pair_amount;

    }

    public static function calculateTomanAmount($total_tether, $tether_price)
    {
        return $tether_price * $total_tether;
    }

    public static function SellOrderCoinex($coinex_order_info, $type, $extra_information = [])
    {


        if ($type == 'market') {

            $data = [
                'access_id' => $coinex_order_info['access_id'],
                'tonce' => $coinex_order_info['tonce'],
                'market' => $coinex_order_info['market'],
                'type' => $coinex_order_info['type'],
                'amount' => $coinex_order_info['amount'],
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
                $i++;
            }

            if ($result->code != 0)
                return self::coinexErrorDetection($result->code);
            elseif ($result->code == 0)
                return $result->data;


        } else {
            //if limit order
            // prepare data for request
            $data = [
                'access_id'=>$coinex_order_info['access_id'],
                'tonce'=>$coinex_order_info['tonce'],
                'market'=>$coinex_order_info['market'],
                'type'=>$coinex_order_info['type'],
                'amount'=>$coinex_order_info['amount'],
                'price' => $coinex_order_info['price'],
                'client_id'=>$coinex_order_info['client_id'],
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
                    default:
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
            case 615:
                $error = __('coinex_error_price_is_more_than_20_percent_different');
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
}
