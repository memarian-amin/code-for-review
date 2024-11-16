<?php

namespace App\Http\Controllers\Api\v1;

use App\Classes\Coinex2;
use App\Classes\Kucoin;
use App\Models\Currency;
use App\Helpers\CoinexApi;
use App\Helpers\SmsHelper;
use App\Models\OrderSetting;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Events\pay_commision;
use App\Helpers\FreezeHelper;
use App\Models\Api\v1\Income;
use App\Helpers\RequestHelper;
use Illuminate\Validation\Rule;
use Lin\Coinex\CoinexExchange;
use App\Models\Api\v1\IncomeLog;
use App\Models\Api\v1\OrdersLog;
use App\Models\Api\v1\UsdtPrice;
use Elegant\Sanitizer\Sanitizer;
use App\Events\OrderVolumenScore;
use App\Models\Api\v1\OrdersList;
use App\Traits\SellExchangeTrait;
use App\Models\Api\v1\RequestType;
use App\Helpers\NotificationHelper;
use App\Models\Api\v1\ExchangeList;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Api\v1\DoneOrdersList;
use App\Models\Api\v1\KucoinCurrency;
use App\Models\Api\v1\BinanceCurrency;
use App\Models\Api\v1\CurrencySetting;
use App\Models\Api\v1\ExchangeSetting;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use App\Repositories\Api\v1\OrderRepository;
use Illuminate\Support\Facades\Notification;
use App\Notifications\UserMessagesNotification;
use App\Models\Api\v1\CoinexMarketInfoExtracted;
use App\Repositories\Api\v1\UserWalletsRepository;
use function config;

/**
 * @group Trade section
 * Api to buy and sell currency
 **/
class SellExchangeController extends Controller
{
    use SellExchangeTrait;

    private $userWalletsRepository;
    private $orderRepository;
    private $network_wage_percent;
    private $notificationHelper;

    public function __construct()
    {
        $notificationService = new NotificationService;
        $this->userWalletsRepository = new UserWalletsRepository;
        $this->orderRepository = new OrderRepository;
        $this->network_wage_percent = config('bitazar.coinex.network_wage_percent');
        $this->notificationHelper = new NotificationHelper($notificationService);
    }

    /**
     * Handle market requests, either for invoicing or selling on supported exchanges.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function market(Request $request)
    {

        $santizier = new Sanitizer($request->all(), [
            'pair_type' => 'strip_tags',
            'pair_amount' => 'strip_tags',
            'request_type' => 'strip_tags',
            'receipt_type' => 'strip_tags',
            'reserve_payment' => 'strip_tags'
        ]);

        $request_sanitized = $santizier->sanitize();

        $validator = Validator::make($request_sanitized, [
            'pair_type' => ['string'],
            'pair_amount' => ['numeric'],
            'request_type' => ['string'],
            'receipt_type' => ['string'],
            'reserve_payment' => ['sometimes', Rule::in([true, false, 'true', 'false'])], // Accept boolean and string 'true'/'false' if present
        ]);

        if ($validator->fails()) {
            return Response::failed($validator->errors()->toArray(), null, 422, -1);
        }

        $tether_price = UsdtPrice::get()->quantity / 10;
        $static_percent = config('bitazar.coinex.wage_percent');

        //we add amount_type for sell request
        $request_sanitized['amount_type'] = 'currency';

        //Add default false values if users doesn't provide wallet values in request
        if (!array_key_exists('usdt_wallet', $request_sanitized))
            $request_sanitized['usdt_wallet'] = false;

        if (!array_key_exists('irr_wallet', $request_sanitized))
            $request_sanitized['irr_wallet'] = false;

        if (!array_key_exists('reserve_payment', $request_sanitized))
            $request_sanitized['reserve_payment'] = false;


        //      Market invoice or sell exchanges
        $exchange_setting = ExchangeSetting::getExchangeSettings();
        switch ($exchange_setting->exchange) {
            case 1:
            default:

                $required_data = [
                    'request' => (object) $request_sanitized,
                    'user' => Auth::guard('api')->user(),
                    'static_percent' => $static_percent,
                    'tether_price' => $tether_price,
                    'order_market_type' => 'market_sell',
                    'network_wage_percent' => $this->network_wage_percent,
                ];
                $coinex_exchange = new Coinex2($required_data);
                return $coinex_exchange->marketSell();

            /*case 2:

                $required_data = [
                    'request' => (object) $request_sanitized,
                    'user' => Auth::guard('api')->user(),
                    'static_percent' => $static_percent,
                    'tether_price' => $tether_price,
                ];
                $kucoin_exchange = new Kucoin($this->userWalletsRepository, $this->orderRepository, $this->userRepository);
                return $kucoin_exchange->marketSell($required_data);


                           case 3:
                               $market_info = BinanceCurrency::getLastInfo();
                               $data = json_decode($market_info->data);
                               foreach ($data as $market_info) {
                                   if ($market_info->symbol == strtoupper($request->pair_type) . 'USDT') {
                                       $maker_fee_rate = ($request->pair_amount * $market_info->price) * 0.1;
                                       $invoice = [];
                                       $wage = SellExchangeTrait::calculateWage($request->pair_amount, $market_info->price, $static_percent);
                                       $total_tether = SellExchangeTrait::calculateTotalTether($request->pair_amount, $market_info->price, $maker_fee_rate, $static_percent);
                                       $toman_amount = SellExchangeTrait::calculateTomanAmount($total_tether, $tether_price);

                                       $pricing_decimal = $coinex_market_info->pricing_decimal;
                                       $trading_decimal = $coinex_market_info->trading_decimal;

                                       switch ($request->receipt_type) {
                                           case 'usdt':
                                           default:
                                               $invoice = [
                                                   'your_payment' => $request->pair_amount,
                                                   'wage_percent' => $wage,
                                                   'currency_type_wage_percent' => 'تتر',
                                                   'your_receipt' => $total_tether,
                                                   'currency_type_your_receipt' => 'تتر',
                                                   'currency_type' => strtoupper($request->pair_type)
                                               ];
                                               break;
                                           case 'toman':
                                               $invoice = [
                                                   'your_payment' => $request->pair_amount,
                                                   'wage_percent' => $wage,
                                                   'currency_type_wage_percent' => 'تتر',
                                                   'your_receipt' => $toman_amount,
                                                   'currency_type_your_receipt' => 'تتر',
                                                   'currency_type' => strtoupper($request->pair_type)
                                               ];
                                               break;
                                       }

                                       if ($request->request_type == 'invoice') {

                                           return Response::success(__('trade_exchange_success_your_exchange_calculated'), $invoice, 200);

                                       } elseif ($request->request_type == 'sell') {
                                           $user_wallet = $this->userWalletsRepository
                                               ->getUserWallet(Auth::guard('api')->id(), strtoupper($request->pair_type));
                                           if ($user_wallet->amount < $request->pair_amount) {
                                               $required_exchange_amount = $request->pair_amount - $user_wallet->amount;
                                               $data = [
                                                   'required_exchange_amount' => $required_exchange_amount
                                               ];

                                               return Response::failed(__('sell_exchange_failed_more_currency_needed'), $data, 422, -2);

                                           } else {
                                               $this->userWalletsRepository
                                                   ->decreaseWalletAmount(Auth::guard('api')->id(), strtoupper($request->pair_type), $request->pair_amount);

                                               switch ($request->receipt_type) {
                                                   case 'usdt':
                                                       $this->userWalletsRepository
                                                           ->increaseWalletAmount(Auth::guard('api')->id(), 'USDT', $total_tether);
                                                       break;
                                                   case 'toman':
                                                   default:
                                                       $this->userWalletsRepository
                                                           ->increaseWalletAmount(Auth::guard('api')->id(), 'IRR', $toman_amount);
                                                       break;
                                               }

                                               $order_info = [
                                                   'user_id' => Auth::guard('api')->id(),
                                                   'time' => now(),
                                                   'market' => strtoupper($request->pair_type) . 'USDT',
                                                   'type' => OrdersList::MARKET_TYPE,
                                                   'role' => OrdersList::SELL_ROLE,
                                                   'order_price' => $toman_amount,
                                                   'avg_price' => 0,
                                                   'amount' => $request->pair_amount,
                                                   'total_price' => $total_tether,
                                                   'current_wage' => $wage,
                                                   'toman_wage' => $tether_price * $wage,
                                                   'currency_price' => $info->last,
                                                   'tether_price' => $tether_price,
                                                   'payment_type' => strtoupper($request->pair_type),
                                                   'filled' => 0,
                                                   'status' => OrdersList::PENDING_STATUS,
                                               ];

                                               OrderRepository::storeBuyOrders($order_info);

                                               $order_setting = OrderSetting::checkOrderMode();
                                               if ($order_setting->mode == OrderSetting::AUTOMATIC_MODE) {
                                                   $coinex_order_info = [
                                                       'access_id' => config('bitazar.coinex.access_id'),
                                                       'market' => strtoupper($request->pair_type) . 'USDT',
                                                       'type' => 'buy',
                                                       'amount' => $request->pair_amount,
                                                       'tonce' => now()->toDateTimeString(),
                                                       'account_id' => 0,
                                                       'client_id' => Auth::guard('api')->user()->mobile
                                                   ];

                                                   SellExchangeTrait::SellOrderCoinex($coinex_order_info, 'market');
                                               }


                                               return Response::success(__('sell_exchange_success_sold'), null, 200);


                                           }
                                       }
                                   }
                               }
                               break;
            */

        }
        //      end market invoice or sell exchanges

    }

    public function limit(Request $request)
    {

        $santizier = new Sanitizer($request->all(), [
            'pair_type' => 'strip_tags',
            'type' => 'strip_tags',
            'pair_price' => 'strip_tags',
            'pair_amount' => 'strip_tags',
            'request_type' => 'strip_tags',
            'receipt_type' => 'strip_tags'
        ]);

        $request_sanitized = $santizier->sanitize();

        $validator = Validator::make($request_sanitized, [
            'pair_type' => ['string'],
            'type' => ['string'],
            'pair_price' => ['numeric'],
            'pair_amount' => ['numeric'],
            'request_type' => ['string'],
            'receipt_type' => ['string']
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
        $static_percent = config('bitazar.coinex.wage_percent');

        //      Limit invoice or sell tether
        /*if ($request->pair_type == 'USDT' || $request->pair_type == 'usdt') {

            $total_price = $request->pair_amount * $request->pair_price;
            $data = [
                'your_payment' => $request->pair_amount, //order quantity
                'wage_percent' => 0,
                'currency_type_wage_percent' => 'تومان',
                'currency_type_wage_percent_en' => 'toman',
                'your_receipt' => $total_price,
                'currency_type_your_receipt' => 'تومان',
                'currency_type_your_receipt_en' => 'toman',
                'currency_type' => 'تتر',
                'currency_type_en' => 'tether'
            ];

            // Check currency status
            $currency_setting = CurrencySetting::getBySymbol($request->pair_type . 'USDT');
            if ($currency_setting)
                if ($currency_setting->status == '-1')
                    return Response::failed('ارز مورد نظر غیر فعال می باشد لطفا در زمانی دیگر تلاش نمایید', null, 422, -1);

            if ($request->request_type == 'invoice') {

                return Response::success(__('trade_exchange_success_tether_amount_calculated'), $data, 200);
            } elseif ($request->request_type == 'sell') {

                $usdt_freeze_amount = FreezeHelper::getFreezeAmount('USDT');

                $user_wallet = $this->userWalletsRepository
                    ->getUserWallet(Auth::guard('api')->id(), 'USDT');

                if (($user_wallet->amount - $usdt_freeze_amount) < $request->pair_amount) {
                    $required_tether_amount  =  $request->pair_amount - ($user_wallet->amount - $usdt_freeze_amount);
                    $data = [
                        'required_tether_amount' => $required_tether_amount
                    ];

                    return Response::failed(__('trade_exchange_failed_tether_are_needed'), $data, 422, -1);
                } else {
                    $this->userWalletsRepository
                        ->decreaseWalletAmount(Auth::guard('api')->id(), 'USDT', $request->pair_amount);
                    $this->userWalletsRepository
                        ->increaseWalletAmount(Auth::guard('api')->id(), 'IRR', $total_price);

                    $order_info = [
                        'user_id' => Auth::guard('api')->id(),
                        'time' => now(),
                        'market' => 'USDT',
                        'type' => OrdersList::LIMIT_TYPE,
                        'role' => OrdersList::SELL_ROLE,
                        'order_price' => $total_price,
                        'avg_price' => 0,
                        'amount' => $request->pair_amount,
                        'total_price' => $total_price,
                        'current_wage' => 0,
                        'toman_wage' => $tether_price * $wage,
                        'currency_price' => $request->pair_price,
                        'tether_price' => $tether_price,
                        'payment_type' => strtoupper($request->pair_type),
                        'filled' => 0,
                        'status' => OrdersList::PENDING_STATUS,
                    ];

                    $order_info = OrderRepository::storeBuyOrders($order_info);

                    $order_setting = OrderSetting::checkOrderMode();
                    if ($order_setting->mode == OrderSetting::AUTOMATIC_MODE) {
                        $coinex_order_info = [
                            'access_id' => config('bitazar.coinex.access_id'),
                            'market' => strtoupper($request->pair_type) . 'USDT',
                            'type' => 'sell',
                            'amount' => $request->pair_amount,
                            'tonce' => now()->toDateTimeString(),
                            'account_id' => 0,
                            'client_id' => Auth::guard('api')->user()->mobile
                        ];

                        SellExchangeTrait::SellOrderCoinex($coinex_order_info, 'limit');
                    }

                    // Send notification to  user
                    $user = Auth::guard('api')->user();
                    $message = 'update_order_completed_content' . ':' . $order_info->id;
                    $title = 'update_buy_order_title' . ':' . $order_info->market;

                    $userId = $user->id; // Replace with the actual user ID
                    $messageData = [
                        'type' => NotificationHelper::NOTIFICATION,
                        'title' => __('update_buy_order_title') . ':' . $order_info->market,
                        'content' => __('update_order_completed_content') . ':' . $order_info->id,
                        // Add any other fields as needed
                    ];
                    $result = $this->notificationHelper->sendNotification($userId, $messageData);


                    Notification::send($user, new UserMessagesNotification($user, $message, $title));

                    return Response::success(__('trade_exchange_success_tether_charged'), null, 200);
                }
            }
        }*/
        //      end limit invoice or sell tether

        //      Limit invoice or sell exchanges
        $exchange_setting = ExchangeSetting::getExchangeSettings();
        switch ($exchange_setting->exchange) {
            case 1:
            default:

                $required_data = [
                    'request' => (object) $request_sanitized,
                    'user' => Auth::guard('api')->user(),
                    'static_percent' => $static_percent,
                    'tether_price' => $tether_price,
                    'order_market_type' => 'limit_sell',
                    'network_wage_percent' => $this->network_wage_percent,
                ];
                $coinex_exchange = new Coinex2($required_data);
                return $coinex_exchange->limitSell();

            /*case 2:
                $market_info = KucoinCurrency::getLastInfo();
                $data = json_decode($market_info->data);
                foreach ($data->data->ticker as $market_info) {
                    if ($market_info->symbol == strtoupper($request->pair_type) . '-USDT') {
                        $maker_fee_rate = $market_info->makerFeeRate;
                        $invoice = [];
                        $wage = SellExchangeTrait::calculateWage($request->pair_amount, $request->pair_price, $static_percent);
                        $total_tether = SellExchangeTrait::calculateTotalTether($request->pair_amount, $request->pair_price, $maker_fee_rate, $static_percent);
                        $toman_amount = SellExchangeTrait::calculateTomanAmount($total_tether, $tether_price);
                        switch ($request->receipt_type) {
                            case 'usdt':
                            default:
                                $invoice =  [
                                    'your_payment' => $request->pair_amount,
                                    'wage_percent' => $wage,
                                    'currency_type_wage_percent' => 'تتر',
                                    'your_receipt' => $total_tether,
                                    'currency_type_your_receipt' => 'تتر',
                                    'currency_type' => strtoupper($request->pair_type)
                                ];
                                break;
                            case 'toman':
                                $invoice =  [
                                    'your_payment' => $request->pair_amount,
                                    'wage_percent' => $wage,
                                    'currency_type_wage_percent' => 'تتر',
                                    'your_receipt' => $toman_amount,
                                    'currency_type_your_receipt' => 'تومان',
                                    'currency_type' => strtoupper($request->pair_type)
                                ];
                                break;
                        }

                        if ($request->request_type == 'invoice') {

                            return Response::success(__('trade_exchange_success_your_exchange_calculated'), $invoice, 200);
                        } elseif ($request->request_type == 'sell') {
                            $user_wallet = $this->userWalletsRepository
                                ->getUserWallet(Auth::guard('api')->id(), strtoupper($request->pair_type));
                            if ($user_wallet->amount < $request->pair_amount) {
                                $required_exchange_amount  =  $request->pair_amount - $user_wallet->amount;
                                $data = [
                                    'required_' . strtolower($request->pair_type) . '_amount' => $required_exchange_amount
                                ];

                                return Response::failed(__('sell_exchange_failed_more_currency_needed'), $data, 422, -2);
                            } else {
                                $this->userWalletsRepository
                                    ->decreaseWalletAmount(Auth::guard('api')->id(), strtoupper($request->pair_type), $request->pair_amount);
                                switch ($request->receipt_type) {
                                    case 'usdt':
                                        $this->userWalletsRepository
                                            ->increaseWalletAmount(Auth::guard('api')->id(), 'USDT', $total_tether);
                                        break;
                                    case 'toman':
                                    default:
                                        $this->userWalletsRepository
                                            ->increaseWalletAmount(Auth::guard('api')->id(), 'IRR', $toman_amount);
                                        break;
                                }

                                $order_info = [
                                    'user_id' => Auth::guard('api')->id(),
                                    'time' => now(),
                                    'market' => strtoupper($request->pair_type) . 'USDT',
                                    'type' => OrdersList::LIMIT_TYPE,
                                    'role' => OrdersList::SELL_ROLE,
                                    'order_price' => $total_tether,
                                    'avg_price' => 0,
                                    'amount' => $request->pair_amount,
                                    'total_price' => $toman_amount,
                                    'current_wage' => $wage,
                                    'toman_wage' => $tether_price * $wage,
                                    'currency_price' => $request->pair_price,
                                    'tether_price' => $tether_price,
                                    'payment_type' => strtoupper($request->pair_type),
                                    'filled' => 0,
                                    'status' => OrdersList::PENDING_STATUS,
                                ];

                                $order_info = OrderRepository::storeBuyOrders($order_info);

                                $order_setting = OrderSetting::checkOrderMode();
                                if ($order_setting->mode == OrderSetting::AUTOMATIC_MODE) {
                                    $coinex_order_info = [
                                        'access_id' => config('bitazar.coinex.access_id'),
                                        'market' => strtoupper($request->pair_type) . 'USDT',
                                        'type' => 'sell',
                                        'amount' => $request->pair_amount,
                                        'tonce' => now()->toDateTimeString(),
                                        'account_id' => 0,
                                        'client_id' => Auth::guard('api')->user()->mobile
                                    ];

                                    SellExchangeTrait::SellOrderCoinex($coinex_order_info, 'limit');
                                }


                                // Send notification to  user
                                $user = Auth::guard('api')->user();
                                $message = 'update_order_completed_content' . ':' . $order_info->id;
                                $title = 'update_buy_order_title' . ':' . $order_info->market;
                                $userId = $user->id; // Replace with the actual user ID
                                $messageData = [
                                    'type' => NotificationHelper::NOTIFICATION,
                                    'title' => __('update_buy_order_title') . ':' . $order_info->market,
                                    'content' => __('update_order_completed_content') . ':' . $order_info->id
                                    // Add any other fields as needed
                                ];
                                $result = $this->notificationHelper->sendNotification($userId, $messageData);
                                Notification::send($user, new UserMessagesNotification($user, $message, $title));


                                return Response::success(__('sell_exchange_success_sold'), null, 200);
                            }
                        }
                    }
                }
                break;
            */
            /*case 3:
                $market_info = BinanceCurrency::getLastInfo();
                $data = json_decode($market_info->data);
                foreach ($data as $market_info) {
                    if ($market_info->symbol == strtoupper($request->pair_type) . 'USDT') {
                        $maker_fee_rate = ($request->pair_amount * $request->pair_price) * 0.1;
                        $invoice = [];
                        $wage = SellExchangeTrait::calculateWage($request->pair_amount, $request->pair_price, $static_percent);
                        $total_tether = SellExchangeTrait::calculateTotalTether($request->pair_amount, $request->pair_price, $maker_fee_rate, $static_percent);
                        $toman_amount = SellExchangeTrait::calculateTomanAmount($total_tether, $tether_price);
                        switch ($request->receipt_type) {
                            case 'usdt':
                            default:
                                $invoice =  [
                                    'your_payment' => $request->pair_amount,
                                    'wage_percent' => $wage,
                                    'currency_type_wage_percent' => 'تتر',
                                    'your_receipt' => $total_tether,
                                    'currency_type_your_receipt' => 'تتر',
                                    'currency_type' => strtoupper($request->pair_type)
                                ];
                                break;
                            case 'toman':
                                $invoice =  [
                                    'your_payment' => $request->pair_amount,
                                    'wage_percent' => $wage,
                                    'currency_type_wage_percent' => 'تتر',
                                    'your_receipt' => $toman_amount,
                                    'currency_type_your_receipt' => 'تومان',
                                    'currency_type' => strtoupper($request->pair_type)
                                ];
                                break;
                        }

                        if ($request->request_type == 'invoice') {

                            return Response::success(__('trade_exchange_success_your_exchange_calculated'), $invoice, 200);
                        } elseif ($request->request_type == 'sell') {
                            $user_wallet = $this->userWalletsRepository
                                ->getUserWallet(Auth::guard('api')->id(), strtoupper($request->pair_type));
                            if ($user_wallet->amount < $request->pair_amount) {
                                $required_exchange_amount  =  $request->pair_amount - $user_wallet->amount;
                                $data = [
                                    'required_' . strtolower($request->pair_type) . '_amount' => $required_exchange_amount
                                ];

                                return Response::failed(__('sell_exchange_failed_more_currency_needed'), $data, 422, -2);
                            } else {
                                $this->userWalletsRepository
                                    ->decreaseWalletAmount(Auth::guard('api')->id(), strtoupper($request->pair_type), $request->pair_amount);
                                switch ($request->receipt_type) {
                                    case 'usdt':
                                        $this->userWalletsRepository
                                            ->increaseWalletAmount(Auth::guard('api')->id(), 'USDT', $total_tether);
                                        break;
                                    case 'toman':
                                    default:
                                        $this->userWalletsRepository
                                            ->increaseWalletAmount(Auth::guard('api')->id(), 'IRR', $toman_amount);
                                        break;
                                }

                                $order_info = [
                                    'user_id' => Auth::guard('api')->id(),
                                    'time' => now(),
                                    'market' => strtoupper($request->pair_type) . 'USDT',
                                    'type' => OrdersList::LIMIT_TYPE,
                                    'role' => OrdersList::SELL_ROLE,
                                    'order_price' => $total_tether,
                                    'avg_price' => 0,
                                    'amount' => $request->pair_amount,
                                    'total_price' => $toman_amount,
                                    'current_wage' => $wage,
                                    'toman_wage' => $tether_price * $wage,
                                    'currency_price' => $request->pair_price,
                                    'tether_price' => $tether_price,
                                    'payment_type' => strtoupper($request->pair_type),
                                    'filled' => 0,
                                    'status' => OrdersList::PENDING_STATUS,
                                ];

                                $order_info = OrderRepository::storeBuyOrders($order_info);

                                $order_setting = OrderSetting::checkOrderMode();
                                if ($order_setting->mode == OrderSetting::AUTOMATIC_MODE) {
                                    $coinex_order_info = [
                                        'access_id' => config('bitazar.coinex.access_id'),
                                        'market' => strtoupper($request->pair_type) . 'USDT',
                                        'type' => 'sell',
                                        'amount' => $request->pair_amount,
                                        'tonce' => now()->toDateTimeString(),
                                        'account_id' => 0,
                                        'client_id' => Auth::guard('api')->user()->mobile
                                    ];

                                    SellExchangeTrait::SellOrderCoinex($coinex_order_info, 'limit');
                                }

                                // Send notification to  user
                                $user = Auth::guard('api')->user();
                                $message = 'update_order_completed_content' . ':' . $order_info->id;
                                $title = 'update_buy_order_title' . ':' . $order_info->market;
                                $userId = $user->id; // Replace with the actual user ID
                                $messageData = [
                                    'type' => NotificationHelper::NOTIFICATION,
                                    'title' => __('update_buy_order_title') . ':' . $order_info->market,
                                    'content' => __('update_order_completed_content') . ':' . $order_info->id,
                                    // Add any other fields as needed
                                ];
                                $result = $this->notificationHelper->sendNotification($userId, $messageData);
                                Notification::send($user, new UserMessagesNotification($user, $message, $title));


                                return Response::success(__('sell_exchange_success_sold'), null, 200);
                            }
                        }
                    }
                }
                break;
            */
        }
    }
}
