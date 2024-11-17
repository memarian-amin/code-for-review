<?php

namespace App\Http\Controllers\Api\v1;

use App\Helpers\CoinexApi;
use Illuminate\Http\Request;
use App\Helpers\RequestHelper;
use App\Models\Api\v1\UsdtPrice;
use Elegant\Sanitizer\Sanitizer;
use App\Models\Api\v1\OrdersList;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use App\Repositories\Api\v1\UserWalletsRepository;


class OrderCalculatorController extends Controller
{

    private $userWalletsRepository;

    public function __construct(UserWalletsRepository $userWalletsRepository)
    {
        $this->userWalletsRepository = $userWalletsRepository;
    }

    public function get(Request $request)
    {
        $santizier = new Sanitizer($request->all(), [
            'currency' => 'strip_tags',
            'from_date' => 'strip_tags',
            'to_date' => 'strip_tags',
        ]);

        $request_sanitized = $santizier->sanitize();

        $validator = Validator::make($request_sanitized, [
            'currency' => ['string'],
            'from_date' => ['date'],
            'to_date' => ['date'],
        ]);

        if ($validator->fails())
            return $validator->errors()->toJson();

        $request_sanitize = (object) $request_sanitized;

        $coinex_wage = 0.2;
        $data['list'] = [];
        $cost_and_benefit = 0;
        $average = 0;
        $percent_by_toman = 0;
        $amount = 0;

        $tether_price = UsdtPrice::get()->quantity / 10;

        // Filter by currency if exist
        if ($request->currency)
            $user_wallets = UserWalletsRepository::getWalletByCurrency(Auth::guard('api')->id(), $request_sanitize->currency);
        else
            $user_wallets = UserWalletsRepository::getWallets(Auth::guard('api')->id());

        foreach ($user_wallets as $user_wallet) {

            // Skip from irr
            if ($user_wallet->wallet == 'IRR')
                continue;

            // Usdt section
            if ($user_wallet->wallet == 'USDT') {

                // Filter by date if exist
                $from_date = @$request_sanitize->from_date;
                $to_date = @$request_sanitize->to_date;

                $orders = OrdersList::findByMarketBuy($user_wallet->wallet, $from_date, $to_date, Auth::guard('api')->id());
                if ($orders)
                    foreach ($orders as $order) {

                        unset($wage);
                        $wage = (($coinex_wage) / 100);

                        $cost_and_benefit += ($order->usdt_price - $wage) / 100;
                        $percent_by_toman += ($order->usdt_price - $wage) * $tether_price;
                        $amount += $order->amount;
                    }

                $data['list'][] = [
                    'currency' => 'USDT',
                    'percent' => $cost_and_benefit,
                    'tether' => $percent_by_toman,
                    'amount' => $amount,
                ];

                $cost_and_benefit = 0;
                $amount = 0;

                continue;
            }

            if (strtoupper($user_wallet->wallet) == 'BTZ')
                continue;

            $params = [
                'market' => strtoupper($user_wallet->wallet) . 'USDT'
            ];
            $result = CoinexApi::send('market/ticker', $params, 'get');

            $info = (object) $result->data->ticker;

            $last_currency_price = $info->last;

            // Filter by date if exist
            $from_date = @$request_sanitize->from_date;
            $to_date = @$request_sanitize->to_date;

            // Others section
            $orders = OrdersList::findByMarketBuy($user_wallet->wallet, $from_date, $to_date, Auth::guard('api')->id());

            if ($orders)
                foreach ($orders as $order) {

                    if (str_contains($order->market, 'IRT')) {

                        unset($order_currency_price);
                        $order_currency_price = $order->currency_price / $order->tether_price;
                        $cost_and_benefit += ($order_currency_price - ($last_currency_price + (($last_currency_price * $coinex_wage) / 100)) / 100);
                    } else {
                        $cost_and_benefit += ($order->currency_price - ($last_currency_price + (($last_currency_price * $coinex_wage) / 100)) / 100);
                    }

                    $amount += $order->amount;
                }

            $data['list'][] = [
                'currency' => $user_wallet->wallet . 'USDT',
                'percent' => $cost_and_benefit,
                'tether' => $cost_and_benefit * $last_currency_price,
                'amount' => $amount,
            ];
        }

        // Count of list for average
        for ($i = 0; $i < count($data['list']); $i++) {
            $average += $data['list'][$i]['percent'];
        }

        // Logic about only when have orders
        $data['average'] = $average != 0 ? $average / $i : 0;

        return Response::success('سود و زیان ارزها', $data, 200);
    }
}
