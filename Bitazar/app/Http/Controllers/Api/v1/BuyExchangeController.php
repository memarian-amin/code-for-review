<?php

namespace App\Http\Controllers\Api\v1;

use App\Classes\Coinex2;
use App\Classes\Kucoin;
use App\Models\Currency;
use App\Helpers\CoinexApi;
use App\Helpers\SmsHelper;
use App\Helpers\CacheHelper;
use App\Models\OrderSetting;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use App\Events\pay_commision;
use App\Helpers\FreezeHelper;
use App\Models\Api\v1\Income;
use App\Helpers\PaymentHelper;
use App\Helpers\RequestHelper;
use Lin\Coinex\CoinexExchange;
use App\Models\Api\v1\IncomeLog;
use App\Models\Api\v1\OrdersLog;
use App\Models\Api\v1\UsdtPrice;
use App\Traits\BuyExchangeTrait;
use Elegant\Sanitizer\Sanitizer;
use App\Events\OrderVolumenScore;
use App\Models\Api\v1\OrdersList;
use App\Models\Api\v1\RequestType;
use App\Models\Api\v1\UserWallets;
use App\Helpers\NotificationHelper;
use App\Models\Api\v1\ExchangeList;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Api\v1\AdminUsdtPrice;
use App\Models\Api\v1\DoneOrdersList;
use App\Models\Api\v1\KucoinCurrency;
use App\Models\Api\v1\BinanceCurrency;
use App\Models\Api\v1\CurrencySetting;
use App\Models\Api\v1\ExchangeSetting;
use App\Models\Api\v1\MongoTradeAmount;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use App\Repositories\Api\v1\UserRepository;
use App\Repositories\Api\v1\OrderRepository;
use Illuminate\Support\Facades\Notification;
use App\Notifications\UserMessagesNotification;
use App\Models\Api\v1\CoinexMarketInfoExtracted;
use App\Models\Api\v1\KucoinMarketInfoExtracted;
use App\Repositories\Api\v1\UserWalletsRepository;
use Illuminate\Validation\Rule;

/**
 * @group Trade section
 * Api to buy and sell currency
 **/
class BuyExchangeController extends Controller
{

    use BuyExchangeTrait;

    private $userWalletsRepository;
    private $orderRepository;
    private $userRepository;
    private $network_wage_percent;
    private $notificationHelper;

    public function __construct()
    {
        $notificationService = new NotificationService;
        $this->userWalletsRepository = new UserWalletsRepository;
        $this->orderRepository = new OrderRepository;
        $this->userRepository = new UserRepository;
        $this->network_wage_percent = config('bitazar.coinex.network_wage_percent');
        $this->notificationHelper = new NotificationHelper($notificationService);
    }

    /**
     * Api to buy currency
     * @bodyParam pair_type string
     * @bodyParam type string
     * @bodyParam pair_price int
     * @bodyParam pair_amount int
     * @bodyParam request_type string
     * @bodyParam irr_wallet boolean
     * @bodyParam usdt_wallet boolean
     **/
    public function market(Request $request)
    {
        $santizier = new Sanitizer($request->all(), [
            'pair_type' => 'strip_tags',
            'type' => 'strip_tags',
            'pair_price' => 'strip_tags',
            'pair_amount' => 'strip_tags',
            'request_type' => 'strip_tags',
            'irr_wallet' => 'strip_tags',
            'usdt_wallet' => 'strip_tags',
            'reserve_payment' => 'strip_tags',
            'amount_type' => 'strip_tags'
        ]);

        $request_sanitized = $santizier->sanitize();

        $validator = Validator::make($request_sanitized, [
            'pair_type' => ['string'],
            'type' => ['string'],
            'pair_price' => ['numeric'],
            'pair_amount' => ['numeric'],
            'request_type' => ['string'],
            'amount_type' => ['string'],
            'irr_wallet' => ['sometimes', Rule::in([true, false, 'true', 'false'])], // Accept boolean and string 'true'/'false' if present
            'usdt_wallet' => ['sometimes', Rule::in([true, false, 'true', 'false'])], // Accept boolean and string 'true'/'false' if present
            'reserve_payment' => ['sometimes', Rule::in([true, false, 'true', 'false'])], // Accept boolean and string 'true'/'false' if present
        ]);

        if ($validator->fails()) {
            return Response::failed($validator->errors()->toArray(), null, 422, -1);
        }

        //Add default false values if users doesn't provide wallet values in request
        if (!array_key_exists('usdt_wallet', $request_sanitized))
            $request_sanitized['usdt_wallet'] = false;

        if (!array_key_exists('irr_wallet', $request_sanitized))
            $request_sanitized['irr_wallet'] = false;

        if (!array_key_exists('reserve_payment', $request_sanitized))
            $request_sanitized['reserve_payment'] = false;


        //get tether price
        $tether_price = UsdtPrice::get()->quantity / 10;
        $static_percent = \config('bitazar.coinex.wage_percent');

        // get which crypto exchange to send request (coinex , kucoin , binance)
        $exchange_setting = ExchangeSetting::getExchangeSettings();
        switch ($exchange_setting->exchange) {
            case 1:
            default:

                $required_data = [
                    'request' => (object) $request_sanitized,
                    'user' => Auth::guard('api')->user() ?? Auth::user(),
                    'static_percent' => $static_percent,
                    'tether_price' => $tether_price,
                    'order_market_type' => 'market_buy',
                    'network_wage_percent' => $this->network_wage_percent
                ];
                $coinex_exchange = new Coinex2($required_data);
                return $coinex_exchange->marketBuy();

                /*case 2:

                $required_data = [
                    'request' => (object) $request_sanitized,
                    'user' => Auth::guard('api')->user(),
                    'static_percent' => $static_percent,
                    'tether_price' => $tether_price,
                ];
                $kucoin_exchange = new Kucoin($this->userWalletsRepository, $this->orderRepository, $this->userRepository);
                return $kucoin_exchange->marketBuy($required_data);

            case 3:
                $market_info = BinanceCurrency::getLastInfo();
                $data = json_decode($market_info->data);
                foreach ($data as $market_info) {
                    if ($market_info->symbol == strtoupper($request->pair_type) . 'USDT') {
                        $taker_fee_rate = $request->pair_amount * 0.1;
                        $invoice = [];
                        $wage = BuyExchangeTrait::calculateWage($request->pair_amount, $market_info->price, $static_percent);
                        $total_tether = BuyExchangeTrait::calculateTotalTether($request->pair_amount, $market_info->price, $taker_fee_rate, $static_percent);
                        $toman_amount = BuyExchangeTrait::calculateTomanAmount($total_tether, $tether_price);

                        $pricing_decimal = $coinex_market_info->pricing_decimal;
                        $trading_decimal = $coinex_market_info->trading_decimal;

                        $invoice = [
                            'your_payment' => $toman_amount,
                            'wage_percent' => $wage,
                            'currency_type_wage_percent' => 'تتر',
                            'your_receipt' => $request->pair_amount,
                            'currency_type_your_receipt' => strtoupper($request->pair_type),
                            'currency_type' => 'تومان'
                        ];

                        if ($request->request_type == 'invoice') {

                            return Response::success(__('trade_exchange_success_your_exchange_calculated'), $invoice, 200);
                        } elseif ($request->request_type == 'buy') {

                            $irr_freeze_amount = FreezeHelper::getFreezeAmount('IRR');
                            $usdt_freeze_amount = FreezeHelper::getFreezeAmount('USDT');

                            $user_usdt_wallet = $this->userWalletsRepository
                                ->getUserWallet(Auth::guard('api')->id(), 'USDT');

                            $user_toman_wallet = $this->userWalletsRepository
                                ->getUserWallet(Auth::guard('api')->id(), 'IRR');

                            if (($user_usdt_wallet->amount - $usdt_freeze_amount) < $total_tether) {

                                // Check toman wallet when user havent credit
                                if (($user_toman_wallet->amount - $irr_freeze_amount) < ($total_tether * $tether_price)) {
                                    $required_amount_tether = $total_tether - $user_usdt_wallet->amount;
                                    $data = [
                                        'required_amount_tether' => $required_amount_tether
                                    ];

                                    return Response::failed(__('trade_exchange_failed_tether_are_needed'), $data, 422, -2);
                                } else {
                                    $this->userWalletsRepository
                                        ->decreaseWalletAmount(Auth::guard('api')->id(), 'IRR', $total_tether * $tether_price);
                                    $this->userWalletsRepository
                                        ->increaseWalletAmount(Auth::guard('api')->id(), strtoupper($request->pair_type), $request->pair_amount);

                                    switch ($request->amount_type) {
                                        case 'usdt':
                                            $payment_type = OrdersList::USDT_PAYMENT;
                                            break;
                                        case 'toman':
                                        default:
                                            $payment_type = OrdersList::IRR_PAYMENT;
                                            break;
                                    }

                                    $order_info = [
                                        'user_id' => Auth::guard('api')->id(),
                                        'time' => now(),
                                        'market' => strtoupper($request->pair_type) . 'USDT',
                                        'type' => OrdersList::MARKET_TYPE,
                                        'role' => OrdersList::BUY_ROLE,
                                        'order_price' => $total_tether,
                                        'avg_price' => 0,
                                        'amount' => $request->pair_amount,
                                        'total_price' => $toman_amount,
                                        'current_wage' => $wage,
                                        'toman_wage' => $tether_price * $wage,
                                        'currency_price' => $info->last,
                                        'tether_price' => $tether_price,
                                        'payment_type' => $payment_type,
                                        'filled' => 0,
                                        'status' => OrdersList::PENDING_STATUS,
                                    ];
                                    $new_order = OrderRepository::storeBuyOrders($order_info);

                                    // Send message of user order
                                    SmsHelper::sendMessage(Auth::guard('api')->user()->mobile, $this->templates_id['market_order'], [$request->pair_amount, $request->pair_type]);

                                    // Send notification to  user
                                    $user = Auth::guard('api')->user();
                                    $message = 'update_quick_order' . ':' . $new_order->id;
                                    $title = 'update_buy_order_title' . ':' . $request->pair_type;
                                    $userId = $user->id; // Replace with the actual user ID
                                    $messageData = [
                                        'type' => NotificationHelper::NOTIFICATION,
                                        'title' => $title,
                                        'content' => $message,
                                        // Add any other fields as needed
                                    ];
                                    $result = $this->notificationHelper->sendNotification($userId, $messageData);
                                    Notification::send($user, new UserMessagesNotification($user, $message, $title));


                                    return Response::success(__('trade_exchange_success_wallet'), null, 200);
                                }
                                // End check toman wallet and operation

                            } else {
                                $this->userWalletsRepository
                                    ->decreaseWalletAmount(Auth::guard('api')->id(), 'USDT', $total_tether);
                                $this->userWalletsRepository
                                    ->increaseWalletAmount(Auth::guard('api')->id(), strtoupper($request->pair_type), $request->pair_amount);

                                switch ($request->amount_type) {
                                    case 'usdt':
                                        $payment_type = OrdersList::USDT_PAYMENT;
                                        break;
                                    case 'toman':
                                    default:
                                        $payment_type = OrdersList::IRR_PAYMENT;
                                        break;
                                }

                                $order_info = [
                                    'user_id' => Auth::guard('api')->id(),
                                    'time' => now(),
                                    'market' => strtoupper($request->pair_type) . 'USDT',
                                    'type' => OrdersList::MARKET_TYPE,
                                    'role' => OrdersList::BUY_ROLE,
                                    'order_price' => $total_tether,
                                    'avg_price' => 0,
                                    'amount' => $request->pair_amount,
                                    'total_price' => $toman_amount,
                                    'current_wage' => $wage,
                                    'toman_wage' => $tether_price * $wage,
                                    'currency_price' => $info->last,
                                    'tether_price' => $tether_price,
                                    'payment_type' => $payment_type,
                                    'filled' => 0,
                                    'status' => OrdersList::PENDING_STATUS,
                                ];

                                $new_order = OrderRepository::storeBuyOrders($order_info);

                                $order_setting = OrderSetting::checkOrderMode();
                                if ($order_setting->mode == OrderSetting::AUTOMATIC_MODE) {
                                    $coinex_order_info = [
                                        'access_id' => config('bitazar.coinex.access_id'),
                                        'market' => strtoupper($request->pair_type) . 'USDT',
                                        'type' => 'buy',
                                        'amount' => $request->pair_amount,
                                        'tonce' => now()->toDateTimeString(),
                                        'account_id' => 0,
                                        'client_id' => @Auth::guard('api')->user()->mobile
                                    ];

                                    BuyExchangeTrait::coinexBuyOrder($coinex_order_info, 'market');
                                }

                                // Send notification to  user
                                $user = Auth::guard('api')->user();
                                $message = 'update_quick_order' . ':' . $new_order->id;
                                $title = 'update_buy_order_title' . ':' . $request->pair_type;
                                $userId = $user->id; // Replace with the actual user ID
                                $messageData = [
                                    'type' => NotificationHelper::NOTIFICATION,
                                    'title' => $title,
                                    'content' => $message,
                                    // Add any other fields as needed
                                ];
                                $result = $this->notificationHelper->sendNotification($userId, $messageData);
                                Notification::send($user, new UserMessagesNotification($user, $message, $title));

                                return Response::success(__('buy_exchange_success_wallet'), null, 200);
                            }
                        }
                    }
                }
                break;*/
        }
    }

    public function limit(Request $request)
    {

        $santizier = new Sanitizer($request->all(), [
            'pair_type' => 'strip_tags',
            'type' => 'strip_tags',
            'pair_price' => 'strip_tags',
            'pair_amount' => 'strip_tags',
            'request_type' => 'strip_tags',
            'irr_wallet' => 'strip_tags',
            'usdt_wallet' => 'strip_tags'
        ]);

        $request_sanitized = $santizier->sanitize();

        $validator = Validator::make($request_sanitized, [
            'pair_type' => ['string'],
            'type' => ['string'],
            'pair_price' => ['numeric'],
            'pair_amount' => ['numeric'],
            'request_type' => ['string'],
            'irr_wallet' => ['sometimes', Rule::in([true, false, 'true', 'false'])], // Accept boolean and string 'true'/'false' if present
            'usdt_wallet' => ['sometimes', Rule::in([true, false, 'true', 'false'])], // Accept boolean and string 'true'/'false' if present
        ]);

        if ($validator->fails()) {
            return Response::failed($validator->errors()->toArray(), null, 422, -1);
        }
        //Add default false values if users doesn't provide wallet values in request
        if (!array_key_exists('usdt_wallet', $request_sanitized))
            $request_sanitized['usdt_wallet'] = false;

        if (!array_key_exists('irr_wallet', $request_sanitized))
            $request_sanitized['irr_wallet'] = false;


        $tether_price = UsdtPrice::get()->quantity / 10;
        $static_percent = \config('bitazar.coinex.wage_percent');


        $exchange_setting = ExchangeSetting::getExchangeSettings();
        switch ($exchange_setting->exchange) {
            case 1:
            default:

                $required_data = [
                    'request' => (object) $request_sanitized,
                    'user' => Auth::guard('api')->user(),
                    'static_percent' => $static_percent,
                    'tether_price' => $tether_price,
                    'order_market_type' => 'limit_buy',
                    'network_wage_percent' => $this->network_wage_percent
                ];
                $coinex_exchange = new Coinex2($required_data);
                return $coinex_exchange->limitBuy();

            /*case 2:

                $required_data = [
                    'request' => (object) $request_sanitized,
                    'user' => Auth::guard('api')->user(),
                    'static_percent' => $static_percent,
                    'tether_price' => $tether_price,
                ];
                $kucoin_exchange = new Kucoin($this->userWalletsRepository, $this->orderRepository, $this->userRepository);
                return $kucoin_exchange->limitBuy($required_data);

            case 3:
                $market_info = BinanceCurrency::getLastInfo();
                $data = json_decode($market_info->data);
                foreach ($data as $market_info) {
                    if ($market_info->symbol == strtoupper($request->pair_type) . 'USDT') {
                        $taker_fee_rate = $request->pair_amount * 0.1;
                        $invoice = [];
                        $wage = BuyExchangeTrait::calculateWage($request->pair_amount, $request->pair_price, $static_percent);
                        $total_tether = BuyExchangeTrait::calculateTotalTether($request->pair_amount, $request->pair_price, $taker_fee_rate, $static_percent);
                        $toman_amount = BuyExchangeTrait::calculateTomanAmount($total_tether, $tether_price);
                        $invoice = [
                            'your_payment' => $total_tether,
                            'wage_percent' => $wage,
                            'currency_type_wage_percent' => 'تتر',
                            'your_receipt' => $request->pair_amount,
                            'currency_type_your_receipt' => $request->pair_type,
                            'currency_type' => 'تتر'
                        ];

                        if ($request->request_type == 'invoice') {

                            return Response::success(__('trade_exchange_success_your_exchange_calculated'), $invoice, 200);
                        } elseif ($request->request_type == 'buy') {
                            $order_info = [
                                'user_id' => Auth::guard('api')->id(),
                                'time' => now(),
                                'market' => strtoupper($request->pair_type) . 'USDT',
                                'type' => OrdersList::LIMIT_TYPE,
                                'role' => OrdersList::BUY_ROLE,
                                'order_price' => $total_tether,
                                'avg_price' => $toman_amount,
                                'amount' => $request->pair_amount,
                                'total_price' => $toman_amount,
                                'current_wage' => $wage,
                                'toman_wage' => $tether_price * $wage,
                                'currency_price' => $request->pair_price,
                                'tether_price' => $tether_price,
                                'payment_type' => OrdersList::USDT_PAYMENT,
                                'filled' => 0,
                                'status' => OrdersList::PENDING_STATUS
                            ];

                            $new_order = OrderRepository::storeBuyOrders($order_info);

                            $order_setting = OrderSetting::checkOrderMode();
                            if ($order_setting->mode == OrderSetting::AUTOMATIC_MODE) {
                                $coinex_order_info = [
                                    'access_id' => config('bitazar.coinex.access_id'),
                                    'market' => strtoupper($request->pair_type) . 'USDT',
                                    'type' => 'buy',
                                    'amount' => $request->pair_amount,
                                    'tonce' => now()->toDateTimeString(),
                                    'account_id' => 0,
                                    'client_id' => @Auth::guard('api')->user()->mobile
                                ];

                                BuyExchangeTrait::coinexBuyOrder($coinex_order_info, 'limit');
                            }


                            return Response::success(__('trade_exchange_success_order'), null, 200);
                        }
                    }
                }
                break;
            */

                //          end limit invoice or buy exchanges
        }
    }
}
