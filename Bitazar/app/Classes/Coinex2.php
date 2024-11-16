<?php


namespace App\Classes;


use App\Jobs\SmsJob;
use App\Models\User;
use App\Events\Scoring;
use App\Helpers\CoinexApi;
use App\Helpers\SmsHelper;
use App\Helpers\UniqueCode;
use App\Models\Api\v1\Logs;
use App\Helpers\CacheHelper;
use App\Models\api\v1\score;
use App\Models\OrderSetting;
use App\Events\pay_commision;
use App\Helpers\FreezeHelper;
use App\Models\Api\v1\Income;
use App\Helpers\PaymentHelper;
use App\Helpers\RequestHelper;
use App\Jobs\PayCommissionJob;
use App\Jobs\StoreIncomeLogJob;
use App\Models\Api\v1\UsersBtz;
use App\Services\CoinexService;
use App\Helpers\PaymentGateways;
use App\Models\Api\v1\FreezeLog;
use App\Models\Api\v1\IncomeLog;
use App\Models\Api\v1\OrdersLog;
use App\Models\Api\v1\UsdtPrice;
use App\Traits\BuyExchangeTrait;
use App\Events\OrderVolumenScore;
use App\Jobs\StoreOrderIncomeJob;
use App\Models\Api\v1\OrdersList;
use App\Traits\SellExchangeTrait;
use App\Jobs\HandleBtzIncreaseJob;
use App\Models\Api\v1\RequestType;
use App\Models\Api\v1\UserWallets;
use Illuminate\Support\Facades\DB;
use App\Helpers\NotificationHelper;
use App\Models\Api\v1\ExchangeList;
use App\Http\Controllers\Controller;
use App\Jobs\AssignFirstTradeBtzJob;
use Illuminate\Support\Facades\Auth;
use App\Models\Api\v1\DoneOrdersList;
use App\Models\Api\v1\PaymentHistory;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use App\Models\Api\v1\CurrencySetting;
use App\Models\Api\v1\MongoTradeAmount;
use App\Jobs\UserMessageNotificationJob;
use Illuminate\Support\Facades\Response;
use App\Models\Api\v1\BitazarLimitOrders;
use App\Repositories\Api\v1\UserRepository;
use App\Repositories\Api\v1\OrderRepository;
use Illuminate\Support\Facades\Notification;
use App\Notifications\UserInviteNotification;
use App\Notifications\UserMessagesNotification;
use App\Models\Api\v1\CoinexMarketInfoExtracted;
use App\Repositories\Api\v1\UserWalletsRepository;
use App\Repositories\Api\v1\NotificationRepository;



class Coinex2 extends Controller
{

    use BuyExchangeTrait, SellExchangeTrait {
        BuyExchangeTrait::coinexErrorDetection insteadof SellExchangeTrait;
        BuyExchangeTrait::calculateWage insteadof SellExchangeTrait;
        BuyExchangeTrait::calculateTomanAmount insteadof SellExchangeTrait;
        BuyExchangeTrait::calculateNetworkWage insteadof SellExchangeTrait;
        BuyExchangeTrait::calculateOrderAmount insteadof SellExchangeTrait;
        BuyExchangeTrait::calculateTotalTether insteadof SellExchangeTrait;
        BuyExchangeTrait::coinexBuyOrder insteadof SellExchangeTrait;
    }

    /**
     * @var \Illuminate\Config\Repository|\Illuminate\Contracts\Foundation\Application|mixed
     */

    private $userWalletsRepository;
    private $orderRepository;
    private $userRepository;
    private $notificationRepository;
    private $notificationHelper;
    private $coinexService;
    private $coinex_access_id;
    private $user;
    private $order_value;
    private $order_qty;
    private $total_value_usdt;
    private $tether_price;
    private $total_value_irt;
    private $order_wage_fee_usdt;
    private $market;
    private $trading_decimal;
    private $request;
    private $coinex_market_info = null;
    private $order_info = null;
    private $coinex_order_info = null;
    private $coinex_order_result = null;
    private $currency_setting = null;
    private $done_order = null;
    private $info;
    private $taker_fee_rate_show;
    private $maker_fee_rate;
    private $payment_type;
    private $network_wage_percent;
    private $min_amount;
    private $pricing_decimal;
    private $temp_payment_type;
    private $pair_price;
    private $count;
    private $total_toman;
    private $amount_type_log;
    private $trade_type;
    private $type_log;
    private $role_log;
    private $order_network_wage_fee_usdt;
    private $total_price;
    private $total_price_type;
    private $order_price;
    private $order_price_type;
    private $order_market_type; //market_buy, market_sell, limit_buy, limit_sell
    private $recived_tether;
    private $wage_percent; //this site own wage (this site income from trades)
    private $network_percent; //coinex wage
    private $order_wage_fee_irt;
    private $order_network_wage_fee_irt;
    private $order_total_fee_usdt; // order value + wage + network wage
    private $order_total_fee_irt;
    private $order_value_usdt; // just order value user has requested (no wages is not added)
    private $order_value_irt;
    private $order_value_coinex_usdt; //used to send order request to coinex  (order value + network wage value usdt)
    private $order_value_coinex_irt;
    private $order_wage_qty;
    private $order_network_wage_qty;
    private $order_qty_coinex;

    //below are used for limit orders on order update
    private $max_qty_user;
    private $max_usdt_user;
    private $max_irt_user;
    private $filled_qty;
    private $filled_usdt;
    private $filled_irt;

    private $order_type;
    private $order_role;
    private $gateway_name;
    private $reserve_payment;

    public function __construct($required_data)
    {
        $notificationService = new NotificationService;
        $this->userWalletsRepository = new UserWalletsRepository();
        $this->orderRepository = new OrderRepository();
        $this->userRepository = new UserRepository();
        $this->notificationHelper = new NotificationHelper($notificationService);
        $this->coinexService = new CoinexService;
        $this->notificationRepository = new NotificationRepository();
        $this->coinex_access_id = config('bitazar.coinex.access_id');
        $this->user = null;

        $this->gateway_name = \config('bitazar.gateway_name');

        $this->definitionOfBasicProperties($required_data);

        // Check currency status
        $this->currency_setting = CurrencySetting::getBySymbol(strtoupper($this->request->pair_type) . 'USDT') ?: CurrencySetting::getBySymbol(strtoupper($this->request->pair_type) . 'USDT');
        if ($this->isCryptoPairEnabledOnSite($this->request->pair_type . 'USDT') == false)
            return Response::failed('ارز مورد نظر غیر فعال می باشد لطفا در زمانی دیگر تلاش نمایید', null, 422, -1);

        // Prepare parameters for fetching the latest price for the cryptocurrency pair
        $marketSymbol = strtoupper($this->request->pair_type) . 'USDT';
        $params = ['market' => $marketSymbol];

        // Fetch the latest price for cryptocurrency pair
        $this->coinex_market_info = (object)$this->getCryptoPairPriceTicker($params)->data->ticker;

        // Is order Market or Limit and also is it buy or sell
        $detect_type_variables = $this->prepareDetectTypeVariables();

        $market_type = 0;

        if ($this->order_market_type == 'market_buy' || $this->order_market_type == 'market_sell') {

            $market_type = OrdersList::MARKET_TYPE;
            //calcuate how many crypto (order_amount , ex. 20 TRX ) and how much in usdt (pair_amount ex. 50 USDT)
            //we convert toman to equivalent USDT here too
            $amounts = $this->calculateAmounts($this->request->amount_type, $this->request->pair_amount, $this->tether_price, $this->coinex_market_info->last);

            $this->order_value = $amounts['pairAmount']; // order_value
            $this->order_qty = $amounts['orderAmount']; // order_qty
        } else { //if it is a limit order (limit buy or limit sell)

            $market_type = OrdersList::LIMIT_TYPE;

            $this->order_qty = $this->request->pair_amount; // order_qty

            //if pair_price is in usdt
            if ($this->request->amount_type == "usdt")
                $this->order_value = $this->request->pair_price * $this->request->pair_amount; // order_value in usdt

            //if pair_price is in toman then we convert it to usdt  order value
            else if ($this->request->amount_type == "toman")
                $this->order_value = ($this->request->pair_price * $this->request->pair_amount) / $this->tether_price;
        }

        if ($this->isUsdt()) {

            $this->wage_percent = $this->calculateStaticPercentForTether();
        } else {

            // Detect wage percent based on orders price value
            $this->wage_percent = $this->calculateWagePercent($this->order_value * $this->tether_price);
        }

        // Get coinex market info to find specific currency and min amount and taker fee rate
        list($this->min_amount, $this->pricing_decimal, $this->trading_decimal) = $this->getMarketInfo(strtoupper($this->request->pair_type) . 'USDT');

        //add this site wage to coinex min_amount - since user has to submit an amount that when we deduct our wage it still be sufficient min amount to send to coinex API
        $this->min_amount = $this->min_amount + ($this->wage_percent * $this->order_qty);


        $fees = $this->calculateFees($this->order_value, $this->order_qty, $market_type, $this->wage_percent, $this->network_percent, $this->request->amount_type, $detect_type_variables['order_role'], $this->market); // order_value_usdt , order_value_irt, order_qty, order_wage_fee_usdt, order_network_wage_fee_usdt, order_total_fee_usdt, total_value_usdt, total_value_irt, order_wage_fee_irt, order_network_wage_fee_irt, order_total_fee_irt

        $this->order_value_usdt = $fees['order_value_usdt'];
        $this->order_value_irt = $fees['order_value_irt'];

        $this->total_value_usdt = $fees['total_value_usdt'];
        $this->total_value_irt = $fees['total_value_irt'];

        $this->order_wage_fee_usdt = $fees['order_wage_fee_usdt'];
        $this->order_wage_fee_irt = $fees['order_wage_fee_irt'];

        $this->order_network_wage_fee_usdt = $fees['order_network_wage_fee_usdt'];
        $this->order_network_wage_fee_irt = $fees['order_network_wage_fee_irt'];

        $this->order_total_fee_usdt = $fees['order_total_fee_usdt'];
        $this->order_total_fee_irt = $fees['order_total_fee_irt'];

        $this->order_value_coinex_usdt = $fees['order_value_coinex_usdt']; // user order value - this site wage (we reduce our wage profit from user order value and then send to coinex)
        $this->order_value_coinex_irt = $fees['order_value_coinex_irt'];

        $this->order_wage_qty = $fees['order_wage_qty']; // this site wage amount
        $this->order_network_wage_qty = $fees['order_network_wage_qty']; // coinex wage amount

        $this->order_qty_coinex = $fees['order_qty_coinex']; //user amount - wage amount of this site (we reduce our wage profit from user amount and then send to coinex)
        //make these prepared for sending to coinex api
        $this->order_qty_coinex =  number_format($this->order_qty_coinex, $this->trading_decimal, '.', '');

        $this->max_qty_user = $fees['max_qty_user'];
        $this->max_usdt_user = $fees['max_usdt_user'];
        $this->max_irt_user = $fees['max_irt_user'];
        $this->filled_qty = $fees['filled_qty'];
        $this->filled_usdt = $fees['filled_usdt'];
        $this->filled_irt = $fees['filled_irt'];


        if ($this->isUsdt()) { // If is usdt

            // order_type, order_role
            $this->order_type = $detect_type_variables['order_type'];
            $this->order_role = $detect_type_variables['order_role'];

            $temp_trade_type = OrdersList::REAL;
        } else {

            // order_type, order_role
            $this->order_type = $detect_type_variables['order_type'];
            $this->order_role = $detect_type_variables['order_role'];

            //is it real or virtual trade
            $temp_trade_type = $this->detectTradeType($this->order_type, $this->order_role);
        }
        $this->definitionOfPropertiesForLog($this->order_type, $this->order_role, $temp_trade_type);
    }

    public function marketBuy()
    {
        DB::beginTransaction();

        try {

            //if it order is to buy tether
            if ($this->isUsdt()) {

                //handle bad request
                if ($this->request->usdt_wallet == 'true')
                    return Response::failed(__('auth_failed_bad_request'), null, 422, -4);

                if ($this->checkTomanLimit() == false)
                    return Response::failed('سفارش شما بیش از حد مجاز است', null, 422, -1);

                if ($this->order_qty < $this->min_amount) {

                    $provide_min_amount_response = [
                        'amount_type' => $this->request->amount_type,
                        'min_amount' => $this->min_amount,
                        'order_amount' => $this->order_qty,
                        'info_last' => $this->coinex_market_info->last,
                        'total_tether' => $this->total_value_usdt,
                        'total_toman' => $this->total_value_irt,
                        'tether_price' => $this->tether_price,
                        'order_total_fee_usdt' => $this->order_total_fee_usdt,
                        'order_total_fee_irt' => $this->order_total_fee_irt,
                        'market' => strtoupper($this->request->pair_type)
                    ];
                    $data = self::provideMinAmountResponse($provide_min_amount_response);

                    return Response::failed(__('trade_exchange_failed_minimum_order'), $data, 422, -4);
                }



                if ($this->isInvoice()) {

                    if ($this->request->irr_wallet == 'true') {

                        $data = $this->buildInvoice(
                            $this->total_value_irt,
                            $this->order_wage_fee_irt,
                            0,
                            'تومان',
                            'toman',
                            $this->max_qty_user,
                            'تتر',
                            'تتر',
                            'tether',
                            'wallet'
                        );
                    } else {


                        if ($this->reserve_payment) {

                            $payment_data = $this->generatePaymentUrl($this->total_value_irt, $this->gateway_name);

                            $payment_url = $payment_data['payment_url'];
                            $trans_id = $payment_data['trans_id'];

                            $payment_id = $this->reserveOnlinePayment(PaymentHistory::MARKET_BUY, $trans_id, $this->gateway_name);
                        } else {

                            $payment_url = '';
                        }

                        $data = $this->buildInvoice(
                            $this->total_value_irt,
                            $this->order_wage_fee_irt,
                            0,
                            'تومان',
                            'toman',
                            $this->max_qty_user,
                            'تتر',
                            'تومان',
                            'toman',
                            'online',
                            $payment_url
                        );
                    }

                    DB::commit();

                    return Response::success(__('trade_exchange_success_tether_amount_calculated'), $data, 200);
                } elseif ($this->isBuy()) {

                    //user is buying tether using toman
                    // Check user has enough tomans in wallet
                    if ($this->request->irr_wallet == 'true') {

                        $toman_wallet = $this->userWalletsRepository->getUserWallet($this->user->id, 'IRR');
                        $irt_freeze_amount = FreezeHelper::getFreezeAmount('IRR');

                        $real_toman_asset = $toman_wallet->amount; // - $irt_freeze_amount;

                        if ($real_toman_asset < $this->total_toman) {

                            $required_amount_toman = $this->total_toman - $real_toman_asset;
                            $data = [
                                'required_amount' => $required_amount_toman
                            ];
                            return Response::failed(__('buy_exchange_failed_toman_are_needed'), $data, 422, -1);
                        }
                    }

                    $this->handleSuccessfulOrder(OrdersList::REAL, OrdersList::IRR_PAYMENT, OrdersList::BUY_ROLE);

                    /*                    $this->userWalletsRepository
                        ->decreaseWalletAmount($this->user->id, 'IRR', $this->max_irt_user); // total_price, $this->order_value_irt
                    $this->userWalletsRepository
                        ->increaseWalletAmount($this->user->id, 'USDT', $this->max_usdt_user); // recived_tether, $this->order_value_usdt

                    $new_order = $this->storeOrderInfo(OrdersList::MARKET_TYPE, OrdersList::BUY_ROLE, OrdersList::REAL, OrdersList::DONE_STATUS);
                    //event(new OrderVolumenScore(Auth::guard('api')->user()));

                    $currency = ExchangeList::findBySymbol(strtoupper($this->request->pair_type));
                    event(new pay_commision($this->user, $this->order_wage_fee_usdt * $this->tether_price, $currency->id));

                    // Send notification to  user

                    $message = 'update_quick_order' . ':' . $new_order->id;
                    $title = 'update_buy_order_title' . ':' . 'USDT';

                    if (str_contains($message, ':')) {

                        $temp_message = explode(':', $message);
                        $final_message = str_replace('[ID]', $temp_message[1], __($temp_message[0]));
                    } else {

                        $final_message = $message;
                    }

                    if (str_contains($title, ':')) {

                        $temp_title = explode(':', $title);
                        $final_title = str_replace('[ID]', $temp_title[1], __($temp_title[0]));
                    } else {

                        $final_title = $title;
                    }

                    $this->notificationRepository::sendSuccessfulNotification($new_order->user_id, __($final_title), __($final_message));

                    $message = 'update_quick_order' . ':' . $new_order->id;
                    $title = 'update_buy_order_title' . ':' . 'USDT';
                    Notification::send($this->user, new UserMessagesNotification($this->user, $message, $title));
*/

                    DB::commit();

                    Cache::forget('wallet_list_' . $this->user->id);

                    return Response::success(__('trade_exchange_success_tether_charged'), null, 200);
                }

                // End market invoice or buy tether


            } else {
                //if user is buying a crypto

                $invoice = [];

                if ($this->checkTomanLimit() == false)
                    return Response::failed('سفارش شما بیش از حد مجاز است', null, 422, -1);


                if ($this->order_qty < $this->min_amount) {

                    $provide_min_amount_response = [
                        'amount_type' => $this->request->amount_type,
                        'min_amount' => $this->min_amount,
                        'order_amount' => $this->order_qty,
                        'info_last' => $this->coinex_market_info->last,
                        'total_tether' => $this->total_value_usdt,
                        'total_toman' => $this->total_value_irt,
                        'tether_price' => $this->tether_price,
                        'order_total_fee_usdt' => $this->order_total_fee_usdt,
                        'order_total_fee_irt' => $this->order_total_fee_irt,
                        'market' => strtoupper($this->request->pair_type)
                    ];
                    $data = self::provideMinAmountResponse($provide_min_amount_response);

                    return Response::failed(__('trade_exchange_failed_minimum_order'), $data, 422, -4);
                }

                //Prepare invoice based on if user wants to pay using USDT wallet, IRR Wallet or Pay directly online (default invoice)
                // if user want to pay using USDT wallet
                if ($this->request->usdt_wallet && $this->request->usdt_wallet == 'true') {

                    //prepare invoice data
                    $invoice = $this->buildInvoice(
                        $this->total_value_usdt,
                        $this->order_wage_fee_usdt,
                        $this->order_network_wage_fee_usdt,
                        'تتر',
                        'tether',
                        $this->max_qty_user,
                        strtoupper($this->request->pair_type),
                        'تتر',
                        'tether',
                        'wallet'
                    );
                }

                // If the user wants to pay using IRR wallet (toman wallet)
                if ($this->request->irr_wallet && $this->request->irr_wallet == 'true') {

                    //prepare invoice data
                    $invoice = $this->buildInvoice(
                        $this->total_value_irt,
                        $this->order_wage_fee_irt,
                        $this->order_network_wage_fee_irt,
                        'تومان',
                        'toman',
                        $this->max_qty_user,
                        strtoupper($this->request->pair_type),
                        'تومان',
                        'toman',
                        'wallet'
                    );
                }


                // Check if it is online direct payment
                if (empty($invoice)) {

                    // Check if toman amount exceeds the maximum limit
                    if ($this->total_value_irt - ($this->order_wage_fee_usdt * $this->tether_price) > 100000000) {
                        return Response::failed('سفارش شما بیش از حد مجاز است', null, 422, -1);
                    }


                    if ($this->reserve_payment) {

                        $payment_data = $this->generatePaymentUrl($this->total_value_irt, $this->gateway_name);

                        $payment_url = $payment_data['payment_url'];
                        $trans_id = $payment_data['trans_id'];

                        $payment_id = $this->reserveOnlinePayment(PaymentHistory::MARKET_BUY, $trans_id, $this->gateway_name);
                    } else {

                        $payment_url = '';
                    }

                    //prepare invoice data
                    $invoice = $this->buildInvoice(
                        $this->total_value_irt,
                        $this->order_wage_fee_irt,
                        $this->order_network_wage_fee_irt,
                        'تومان',
                        'toman',
                        $this->max_qty_user,
                        strtoupper($this->request->pair_type),
                        'تومان',
                        'toman',
                        'online',
                        $payment_url
                    );
                }


                if ($this->isInvoice() || ($this->reserve_payment == true)) {

                    DB::commit();

                    return Response::success(__('trade_exchange_success_your_exchange_calculated'), $invoice, 200);
                } elseif ($this->request->request_type == 'buy') {

                    // Check user order amount with minimum amount of order
                    if ($this->order_qty < $this->min_amount) {

                        $provide_min_amount_response = [
                            'amount_type' => $this->request->amount_type,
                            'min_amount' => $this->min_amount,
                            'order_amount' => $this->order_qty,
                            'info_last' => $this->coinex_market_info->last,
                            'total_tether' => $this->total_value_usdt,
                            'tether_price' => $this->tether_price,
                            'market' => strtoupper($this->request->pair_type)
                        ];
                        $data = self::provideMinAmountResponse($provide_min_amount_response);

                        return Response::failed(__('trade_exchange_failed_minimum_order'), $data, 422, -4);
                    }

                    // Check user asset to order
                    if ($this->request->irr_wallet == 'true') {

                        // TODO we have real value in database need to change
                        $toman_wallet = $this->userWalletsRepository->getUserWallet($this->user->id, 'IRR');
                        $irt_freeze_amount = FreezeHelper::getFreezeAmount('IRR');

                        $real_toman_asset = $toman_wallet->amount; // - $irt_freeze_amount;

                        if ($real_toman_asset < $this->total_value_irt) {

                            $required_amount_toman = $this->total_value_irt - $real_toman_asset;
                            $data = [
                                'required_amount' => $required_amount_toman
                            ];
                            return Response::failed(__('buy_exchange_failed_toman_are_needed'), $data, 422, -1);
                        } else {
                            //if user has enough tomans (IRT) in the wallet we send order to coinex
                            $this->processCoinexOrder(OrdersList::MARKET_TYPE, OrdersList::BUY_ROLE, OrdersList::REAL);

                            // Process by trade type (ex: REAL or VIRTUAL)
                            switch ($this->trade_type) {

                                    // Real trade
                                case OrdersList::REAL:

                                    //if order on coinex was successful
                                    if (!@$this->coinex_order_result->code) {

                                        $this->handleSuccessfulOrder(OrdersList::REAL, OrdersList::IRR_PAYMENT, OrdersList::BUY_ROLE);

                                        DB::commit();

                                        return Response::success(__('trade_exchange_success_wallet'), null, 200);
                                    } else {

                                        $this->handleFailureOrder(OrdersList::REAL, OrdersList::BUY_ROLE, OrdersList::MARKET_TYPE);

                                        DB::commit();

                                        if (@$this->coinex_order_result->error)
                                            return Response::failed($this->coinex_order_result->error, null, 400, -3);
                                        else
                                            return Response::failed(__('trade_exchange_failed_problem_about_operation'), null, 400, -3);
                                    }


                                    // Virtual trade
                                case OrdersList::VIRTUAL:

                                    $this->handleSuccessfulOrder(OrdersList::VIRTUAL, OrdersList::IRR_PAYMENT, OrdersList::BUY_ROLE);

                                    DB::commit();

                                    return Response::success(__('trade_exchange_success_wallet'), null, 200);
                            }
                        }
                    }

                    // Check user asset to order
                    if ($this->request->usdt_wallet == 'true') {

                        $usdt_wallet = $this->userWalletsRepository->getUserWallet($this->user->id, 'USDT');
                        $usdt_freeze_amount = FreezeHelper::getFreezeAmount('USDT');

                        $real_usdt_asset = $usdt_wallet->amount; // - $usdt_freeze_amount;

                        if ($real_usdt_asset < $this->total_value_usdt) {

                            $required_amount_tether = $this->total_value_usdt - $real_usdt_asset;
                            $data = [
                                'required_amount' => $required_amount_tether
                            ];
                            return Response::failed(__('trade_exchange_failed_tether_are_needed'), $data, 422, -2);
                        } else {

                            $this->processCoinexOrder(OrdersList::MARKET_TYPE, OrdersList::BUY_ROLE, OrdersList::REAL);

                            // Process by trade type (ex: REAL or VIRTUAL)
                            switch ($this->trade_type) {

                                    // Real trade
                                case OrdersList::REAL:

                                    //if order on coinex was successful
                                    if (!@$this->coinex_order_result->code) {

                                        $this->handleSuccessfulOrder(OrdersList::REAL, OrdersList::USDT_PAYMENT, OrdersList::BUY_ROLE);

                                        DB::commit();

                                        return Response::success(__('trade_exchange_success_wallet'), null, 200);
                                    } else {

                                        $this->handleFailureOrder(OrdersList::REAL, OrdersList::BUY_ROLE, OrdersList::MARKET_TYPE);

                                        DB::commit();
                                        if (@$this->coinex_order_result->error)
                                            return Response::failed($this->coinex_order_result->error, null, 400, -3);
                                        else
                                            return Response::failed(__('trade_exchange_failed_problem_about_operation'), null, 400, -3);
                                    }


                                    // Virtual trade
                                case OrdersList::VIRTUAL:

                                    $this->handleSuccessfulOrder(OrdersList::VIRTUAL, OrdersList::USDT_PAYMENT, OrdersList::BUY_ROLE);

                                    DB::commit();

                                    return Response::success(__('trade_exchange_success_wallet'), null, 200);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $exception) {

            return $this->handleRollbackException($exception, 'Coinex2', 'marketBuy');
        }
    }

    public function limitBuy()
    {

        DB::beginTransaction();

         if ($this->request->irr_wallet == false && $this->request->usdt_wallet == false)
             return Response::failed(__('speify_type_of_wallet'), null, 422, -4);

        try {

            //convert pair price in usdt if it is in toman
            $this->pair_price = $this->calculatePairPrice();

            $invoice = [];

            if ($this->isInvoice()) {

                $invoice = $this->buildInvoice(
                    $this->total_value_usdt,
                    $this->order_wage_fee_usdt,
                    $this->order_network_wage_fee_usdt,
                    'تتر',
                    'tether',
                    $this->max_qty_user,
                    $this->request->pair_type,
                    'تتر',
                    'tether',
                    'wallet'
                );

                DB::commit();

                return Response::success(__('trade_exchange_success_your_exchange_calculated'), $invoice, 200);
            } elseif ($this->request->request_type == 'buy') {

                // Check user amount with minimum amount of order
                if ($this->order_qty < $this->min_amount) {
                    $data = [
                        'min_required_pair_amount' => $this->min_amount,
                        'your_pair_amount' => $this->order_qty,
                        'difference_in_min_amount' => $this->min_amount - $this->order_qty
                    ];

                    return Response::failed(__('trade_exchange_failed_minimum_order'), $data, 200, -4);
                }


                // Check user asset to order
                switch ($this->request->amount_type) {
                    case 'usdt':
                        // TODO check freeze by database
                        $usdt_freeze_amount = FreezeHelper::getFreezeAmount('USDT');
                        $usdt_wallet = $this->userWalletsRepository
                            ->getUserWallet($this->user->id, 'USDT');

                        $this->temp_payment_type = OrdersList::USDT_PAYMENT;

                        $real_usdt_asset = $usdt_wallet->amount; // - $usdt_freeze_amount;

                        if ($real_usdt_asset < $this->total_value_usdt) {

                            $required_amount_tether = $this->total_value_usdt - $real_usdt_asset;

                            $data = [
                                'required_amount' => $required_amount_tether
                            ];
                            return Response::failed(__('trade_exchange_failed_tether_are_needed'), $data, 422, -2);
                        }
                        break;

                    case 'toman':
                    default:
                        // TODO check freeze by database
                        $irt_freeze_amount = FreezeHelper::getFreezeAmount('IRR');
                        $irt_wallet = $this->userWalletsRepository
                            ->getUserWallet($this->user->id, 'IRR');

                        $this->temp_payment_type = OrdersList::IRR_PAYMENT;

                        $real_irt_asset = $irt_wallet->amount; // - $irt_freeze_amount;

                        if ($real_irt_asset < $this->total_value_irt) {

                            $required_amount_toman = $this->total_value_irt - $real_irt_asset;

                            $data = [
                                'required_amount' => $required_amount_toman
                            ];
                            return Response::failed(__('buy_exchange_failed_toman_are_needed'), $data, 422, -2);
                        }

                        break;
                }


                //send limit order to coinex
                $this->processCoinexOrder(OrdersList::LIMIT_TYPE, OrdersList::BUY_ROLE, OrdersList::REAL);


                // Process by trade type (ex: REAL or VIRTUAL)
                switch ($this->trade_type) {

                        // Real trade
                    case OrdersList::REAL:

                        //if order on coinex was successful
                        if (!@$this->coinex_order_result->code) {

                            $is_done = $this->checkIsDone();

                            $this->handleSuccessfulLimitOrder(OrdersList::REAL, $this->temp_payment_type, OrdersList::BUY_ROLE, $is_done);

                            DB::commit();

                            Cache::forget('wallet_list_' . $this->user->id);

                            return Response::success(__('trade_exchange_success_order'), null, 200);
                        } else {

                            $this->handleFailureOrder(OrdersList::REAL, OrdersList::BUY_ROLE, OrdersList::LIMIT_TYPE);

                            DB::commit();

                            if (@$this->coinex_order_result->error)
                                return Response::failed($this->coinex_order_result->error, null, 400, -3);
                            else
                                return Response::failed(__('trade_exchange_failed_problem_about_operation'), null, 400, -3);
                        }


                        // Virtual trade
                    case OrdersList::VIRTUAL:

                        $this->handleSuccessfulLimitOrder(OrdersList::VIRTUAL, $this->temp_payment_type,  OrdersList::BUY_ROLE);

                        DB::commit();

                        return Response::success(__('trade_exchange_success_order'), null, 200);
                }
            }
        } catch (\Exception $exception) {

            return $this->handleRollbackException($exception, 'Coinex2', 'limitBuy');
        }
    }

    public function marketSell()
    {

        DB::beginTransaction();

        try {

            if ($this->reserve_payment == true)
                return Response::failed(__('no_allow_access_reserve_payment'), null, 422, -4);

            // If user is selling usdt
            if ($this->isUsdt()) {

                //handle bad request
                if ($this->request->usdt_wallet == 'true')
                    return Response::failed(__('auth_failed_bad_request'), null, 422, -4);

                if ($this->order_qty < $this->min_amount) {

                    $provide_min_amount_response = [
                        'amount_type' => $this->request->amount_type,
                        'min_amount' => $this->min_amount,
                        'order_amount' => $this->order_qty,
                        'info_last' => $this->coinex_market_info->last,
                        'total_tether' => $this->total_value_usdt,
                        'total_toman' => $this->total_value_irt,
                        'tether_price' => $this->tether_price,
                        'order_total_fee_usdt' => $this->order_total_fee_usdt,
                        'order_total_fee_irt' => $this->order_total_fee_irt,
                        'market' => strtoupper($this->request->pair_type)
                    ];
                    $data = self::provideMinAmountResponse($provide_min_amount_response);

                    return Response::failed(__('trade_exchange_failed_minimum_order'), $data, 422, -4);
                }



                if ($this->isInvoice()) {

                    $data = $this->buildInvoice(
                        $this->order_qty,
                        $this->order_wage_fee_irt,
                        0,
                        'تومان',
                        'toman',
                        $this->max_irt_user,
                        'toman',
                        'تتر',
                        'tether',
                        'wallet'
                    );

                    DB::commit();

                    return Response::success(__('sell_exchange_success_toman_calculated'), $data, 200);
                } elseif ($this->request->request_type == 'sell') {
                    //User is selling tether

                    // Check user asset
                    $usdt_wallet = $this->userWalletsRepository->getUserWallet($this->user->id, 'USDT');
                    $usdt_freeze_amount = FreezeHelper::getFreezeAmount('USDT');

                    $real_usdt_asset = $usdt_wallet->amount; // - $usdt_freeze_amount;

                    if ($real_usdt_asset < $this->request->pair_amount) {

                        $required_amount_tether = $this->request->pair_amount - $real_usdt_asset;
                        $data = [
                            'required_amount' => $required_amount_tether
                        ];
                        return Response::failed(__('trade_exchange_failed_tether_are_needed'), $data, 422, -1);
                    }

                    $this->handleSuccessfulOrder(OrdersList::REAL, OrdersList::IRR_PAYMENT, OrdersList::SELL_ROLE);

                    $this->userWalletsRepository
                        ->decreaseWalletAmount($this->user->id, 'USDT', $this->max_qty_user); // $this->request->pair_amount, $this->request->pair_amount

                    $this->userWalletsRepository
                        ->increaseWalletAmount($this->user->id, 'IRR', $this->max_irt_user); // $this->total_price - $this->order_wage_fee_usdt, $this->total_price - $this->order_wage_fee_usdt

                    $this->order_info = $this->storeOrderInfo(OrdersList::MARKET_TYPE, OrdersList::SELL_ROLE, OrdersList::REAL);

                    // Send notification to  user
                    $message = 'update_quick_order' . ':' . $this->order_info->id;
                    $title = 'update_sell_order_title' . ':' . $this->request->pair_type;

                    if (str_contains($message, ':')) {

                        $temp_message = explode(':', $message);
                        $final_message = str_replace('[ID]', $temp_message[1], __($temp_message[0]));
                    } else {

                        $final_message = $message;
                    }

                    if (str_contains($title, ':')) {

                        $temp_title = explode(':', $title);
                        $final_title = str_replace('[ID]', $temp_title[1], __($temp_title[0]));
                    } else {

                        $final_title = $title;
                    }
                    $this->sendSuccessfulNotification(__($final_title), __($final_message));


                    Notification::send($this->user, new UserMessagesNotification($this->user, $message, $title));

                    DB::commit();

                    Cache::forget('wallet_list_' . $this->user->id);

                    return Response::success(__('trade_exchange_success_toman_charged'), null, 200);
                }
            } else {
                //If it is market sell order of a crypto

                $invoice = [];

                switch ($this->request->receipt_type) {
                    case 'usdt':
                    default:
                        $invoice = $this->buildInvoice(
                            $this->request->pair_amount,
                            $this->order_wage_fee_usdt,
                            $this->order_network_wage_fee_usdt,
                            'تتر',
                            'tether',
                            $this->max_usdt_user,
                            strtoupper($this->request->pair_type),
                            'تتر',
                            'tether',
                            'wallet'
                        );

                        break;
                    case 'toman':
                        $invoice = $this->buildInvoice(
                            $this->order_qty,
                            $this->order_wage_fee_irt,
                            $this->order_network_wage_fee_irt,
                            'تومان',
                            'toman',
                            $this->max_irt_user,
                            strtoupper($this->request->pair_type),
                            'تومان',
                            'toman',
                            'wallet'
                        );

                        break;
                }


                if ($this->isInvoice()) {

                    DB::commit();

                    return Response::success(__('trade_exchange_success_your_exchange_calculated'), $invoice, 200);
                } elseif ($this->request->request_type == 'sell') {

                    // Check user amount with minimum amount of order
                    if ($this->order_qty < $this->min_amount) {
                        $data = [
                            'min_required_pair_amount' => $this->min_amount,
                            'your_pair_amount' => $this->request->pair_amount,
                            'difference_in_min_amount' => $this->min_amount - $this->request->pair_amount
                        ];

                        return Response::failed(__('trade_exchange_failed_minimum_order'), $data, 200, -4);
                    }

                    // TODO check freeze amount by database
                    // Check user asset to order
                    $pair_wallet = $this->userWalletsRepository->getUserWallet($this->user->id, strtoupper($this->request->pair_type));
                    $pair_freeze_amount = FreezeHelper::getFreezeAmount(strtoupper($this->request->pair_type));

                    $real_toman_asset = $pair_wallet->amount; // - $pair_freeze_amount;

                    if ($real_toman_asset < $this->request->pair_amount) {

                        $required_exchange_amount = $this->request->pair_amount - $real_toman_asset;
                        $data = [
                            'required_amount' => $required_exchange_amount
                        ];
                        return Response::failed(__('sell_exchange_failed_more_currency_needed'), $data, 422, -1);
                    } else {

                        $this->processCoinexOrder(OrdersList::MARKET_TYPE, OrdersList::SELL_ROLE, OrdersList::REAL);

                        // Process by trade type (ex: REAL or VIRTUAL)
                        switch ($this->trade_type) {

                                // Real trade
                            case OrdersList::REAL:

                                //if order on coinex was successful
                                if (!@$this->coinex_order_result->code) {

                                    $this->handleSuccessfulOrder(OrdersList::REAL, null, OrdersList::SELL_ROLE);

                                    DB::commit();

                                    return Response::success(__('sell_exchange_success_sold'), null, 200);
                                } else {

                                    $this->handleFailureOrder(OrdersList::REAL, OrdersList::SELL_ROLE, OrdersList::MARKET_TYPE);

                                    // SmsHelper::sendMessage('09122335645', 74293, [$this->user->mobile . '' . OrdersList::MARKET_TYPE, $this->coinex_order_result->error]);

                                    SmsJob::dispatch('09122335645', 74293, [$this->user->mobile . '' . OrdersList::MARKET_TYPE, $this->coinex_order_result->error]);

                                    DB::commit();
                                    if (@$this->coinex_order_result->error)
                                        return Response::failed($this->coinex_order_result->error, null, 400, -3);
                                    else
                                        return Response::failed(__('trade_exchange_failed_problem_about_operation'), null, 400, -3);
                                }


                                // Virtual trade
                            case OrdersList::VIRTUAL:

                                $this->handleSuccessfulOrder(OrdersList::VIRTUAL, null, OrdersList::SELL_ROLE);

                                return Response::success(__('sell_exchange_success_sold'), null, 200);
                        }
                    }
                }
            }
        } catch (\Exception $exception) {

            return $this->handleRollbackException($exception, 'Coinex2', 'marketSell');
        }
    }

    public function limitSell()
    {

        DB::beginTransaction();

        try {

            $this->pair_price = $this->calculatePairPrice();

            $invoice = [];

            if ($this->isInvoice()) {

                switch ($this->request->receipt_type) {
                    case 'usdt':
                    default:
                        $invoice = $this->buildInvoice(
                            $this->request->pair_amount,
                            $this->order_wage_fee_usdt,
                            $this->order_network_wage_fee_usdt,
                            'تتر',
                            'tether',
                            $this->max_usdt_user,
                            'تتر',
                            strtoupper($this->request->pair_type),
                            'tether',
                            'wallet'
                        );

                        break;
                    case 'toman':
                        $invoice = $this->buildInvoice(
                            $this->request->pair_amount,
                            $this->order_wage_fee_usdt * $this->tether_price,
                            $this->order_network_wage_fee_usdt * $this->tether_price,
                            'تتر',
                            'tether',
                            $this->max_irt_user,
                            'تومان',
                            strtoupper($this->request->pair_type),
                            'تومان',
                            'wallet'
                        );

                        break;
                }

                DB::commit();

                return Response::success(__('trade_exchange_success_your_exchange_calculated'), $invoice, 200);
            } elseif ($this->request->request_type == 'sell') {

                // Check user asset to order
                $pair_wallet = $this->userWalletsRepository->getUserWallet($this->user->id, strtoupper($this->request->pair_type));
                $pair_freeze_amount = FreezeHelper::getFreezeAmount(strtoupper($this->request->pair_type));

                $real_exchange_asset = $pair_wallet->amount; // - $pair_freeze_amount;

                if ($real_exchange_asset < $this->request->pair_amount) {

                    $required_exchange_amount = $this->request->pair_amount - $real_exchange_asset;
                    $data = [
                        'required_amount' => $required_exchange_amount
                    ];
                    return Response::failed(__('sell_exchange_failed_more_currency_needed'), $data, 422, -1);
                }


                // Define payment type (ex: IRR or USDT)
                $is_usdt_wallet = $this->request->usdt_wallet ? true : false;
                $is_irr_wallet = $this->request->irr_wallet ? true : false;

                $this->temp_payment_type = self::detectPaymentType($this->request->amount_type, $this->request->pair_type, $is_usdt_wallet, $is_irr_wallet);

                $this->processCoinexOrder(OrdersList::LIMIT_TYPE, OrdersList::SELL_ROLE, OrdersList::REAL);

                if (!@$this->coinex_order_result->code) {

                    $is_done = $this->checkIsDone();

                    $this->handleSuccessfulLimitOrder(OrdersList::REAL, $this->temp_payment_type, OrdersList::SELL_ROLE, $is_done);

                    DB::commit();

                    Cache::forget('wallet_list_' . $this->user->id);

                    return Response::success(__('trade_exchange_success_order'), null, 200);
                } else {

                    $this->handleFailureOrder(OrdersList::REAL, OrdersList::SELL_ROLE, OrdersList::LIMIT_TYPE);

                    DB::commit();

                    if (@$this->coinex_order_result->error)
                        return Response::failed($this->coinex_order_result->error, null, 400, -3);
                    else
                        return Response::failed(__('trade_exchange_failed_problem_about_operation'), null, 400, -3);
                }
            }
        } catch (\Exception $exception) {

            return $this->handleRollbackException($exception, 'Coinex2', 'limitSell');
        }
    }

    /**
     * Get market information for a specific crypto pair for example ETHUSDT, BTCUSDT.
     *
     * @param string $cryptoPair
     * @return array
     */
    private function getMarketInfo($cryptoPair)
    {
        // Get coinex market info for finding specific currency and min amount and taker fee rate
        $coinexMarketInfo = $this->coinexService->getCryptoPairMarketInfo(strtoupper($cryptoPair));

        if ($cryptoPair == 'BTZUSDT' || $cryptoPair == 'IRRUSDT' || $cryptoPair == 'USDTUSDT') {

            switch ($cryptoPair) {

                case 'BTZUSDT':
                    return [
                        1000,
                        0,
                        2
                    ];

                case 'USDTUSDT':
                    return [
                        6,
                        6,
                        8
                    ];

                case 'IRRUSDT':
                    return [
                        5000,
                        0,
                        2
                    ];
            }
        } else {

            return [
                $coinexMarketInfo->min_amount,
                //$coinexMarketInfo->taker_fee_rate,
                $coinexMarketInfo->pricing_decimal,
                $coinexMarketInfo->trading_decimal,
            ];
        }
    }

    /**
     * Check if the crypto pair is enabled for trading on the site.
     *
     * @param string $cryptoPairSymbol ex. ETHUSDT, ADAUSDT
     * @return bool
     */
    private function isCryptoPairEnabledOnSite($cryptoPairSymbol)
    {
        $cryptoPairSetting = CurrencySetting::getBySymbol($cryptoPairSymbol);

        if ($cryptoPairSetting && $cryptoPairSetting->status == '-1') {
            return false;
        }

        return true;
    }

    /**
     * Send request to get crpytopair market ticker based on the request type.
     *
     * @param array $params
     * @return mixed
     */
    private function getCryptoPairPriceTicker($params)
    {
        if ($params['market'] == 'BTZUSDT' || $params['market'] == 'IRRUSDT' || $params['market'] == 'USDTUSDT') {

            $result = new \stdClass();
            $result->data = new \stdClass();

            switch ($params['market']) {

                case 'BTZUSDT':
                    $btz_price = config('bitazar.btz_price');
                    $result->data->ticker = new PairTicker(0, 0, 0, 0, $btz_price, 0, 0, 0, 0);
                    return $result;
                case 'USDTUSDT':
                    $result->data->ticker = new PairTicker(0, 0, 0, 0, 1, 0, 0, 0, 0);
                    return $result;

                case 'IRRUSDT':
                    $tether_price = UsdtPrice::get()->quantity / 10;
                    $result->data->ticker = new PairTicker(0, 0, 0, 0, $tether_price, 0, 0, 0, 0);
                    return $result;
            }
        } else {

            $requestType = RequestType::get();

            switch ($requestType->en_name) {
                case RequestType::EN_CLOUD:
                default:
                    $result = \App\Helpers\CoinexApi::send('market/ticker', $params, 'get');
                    break;
                case RequestType::EN_FOREIGN:
                    $result = \App\Helpers\RequestHelper::send('https://api.coinex.com/v1/market/ticker', 'get', $params, null);
                    break;
            }
        }

        return $result;
    }

    /**
     * Calculate pair amount and order amount based on user input.
     * @orderAmount order amount is how many crypto we want to buy
     * @pairAmount pair amount is how much it costs in total in usdt
     * @amountType (toman or usdt or currency)
     * @param string $amountType
     * @param float $pairAmount
     * @param float $tetherPrice
     * @param float $cryptoLastPrice
     * @return array
     */
    private function calculateAmounts($amountType, $pairAmount, $tetherPrice, $cryptoLastPrice)
    {
        // Original comment: we calculate pair_amount and order_amount
        // ...

        $pairAmountData = [
            'amount_type' => $amountType,
            'pair_amount' => $pairAmount,
            'tether_price' => $tetherPrice
        ];

        // Adjust pair amount if input is in currency
        if ($amountType === 'currency') {

            // Original comment: calculate how much for example 250 dogecoins is in usdt?
            $orderAmount = $pairAmount;

            $pairAmount = ($orderAmount * $cryptoLastPrice); // order_value

        } else {

            // Calculate pair amount in USDT
            $pairAmount = $this->calculatePairAmount($pairAmountData); // order_value

            // Calculate order amount based on user input
            $orderAmount = $this->calculateOrderAmount($pairAmount, $cryptoLastPrice); // order_qty

        }

        // Remove commas from $pair_amount and $currency_price
        $pairAmount = str_replace(',', '', $pairAmount);
        $orderAmount = str_replace(',', '', $orderAmount);

        return compact('pairAmount', 'orderAmount');
    }

    /**
     *  Allocate BTZ rewards to the user based on the provided score key.
     *
     * @param string $scoreKey The key associated with the score.
     *
     * @return void
     */

    private function allocateBtzRewards($scoreKey)
    {
        $score = Score::where('key', $scoreKey)->first();
        $userBtz = UsersBtz::store($this->user->id, $score->value, $score->id);
        User::increaseWalletAmount($this->user->id, 'BTZ', $score->value);
    }

    /**
     * Store income log for a buy order.
     *
     * @param object $orderInfo The information related to the order.
     *
     * @return void
     */
    private function storeIncomeLog($role)
    {
        // Define payment type (ex: IRR or USDT)
        $is_usdt_wallet = $this->request->usdt_wallet ? true : false;
        $is_irr_wallet = $this->request->irr_wallet ? true : false;

        $payment_type = self::detectPaymentType($this->request->amount_type, $this->request->pair_type, $is_usdt_wallet, $is_irr_wallet);
        $market_name = strtoupper($this->defineMarketName($payment_type));

        $wage = 0;
        $wage_type = 'unknown';
        $type = OrdersList::MARKET_TYPE;

        switch ($role) {
            case OrdersList::BUY_ROLE:
                if (strpos($market_name, 'IRT') !== false) {
                    $wage_type = 'IRR';
                    $wage = $this->order_wage_fee_irt;
                } elseif (strpos($market_name, 'USDT') !== false) {
                    $wage_type = 'USDT';
                    $wage = $this->order_wage_fee_usdt;
                }
                break;
            case OrdersList::SELL_ROLE:
                $wage_type = str_replace(['IRT', 'USDT'], '', $market_name);
                $wage = $this->order_wage_qty;
                break;
        }

        $incomeLog = [
            'user_id' => $this->user->id,
            'order_id' => $this->order_info->id,
            'wage_type' => $wage_type,
            'wage' => $wage,
            'type' => $type,
            'role' => $role,
            'currency' => $market_name
        ];
        IncomeLog::store($incomeLog);
    }

    /**
     * Store daily income based on the current Tether price and wage.
     *
     * @return voidxzz
     */
    // private function storeDailyIncome()
    // {
    //     // $payment_type = strtoupper($this->request->pair_type);

    //     $quantity = $this->tether_price * $this->order_wage_fee_usdt;
    //     Income::store($quantity);
    // }

    private function storeOrderInome($role)
    {
        // Define payment type (ex: IRR or USDT)
        $is_usdt_wallet = $this->request->usdt_wallet ? true : false;
        $is_irr_wallet = $this->request->irr_wallet ? true : false;

        $payment_type = self::detectPaymentType($this->request->amount_type, $this->request->pair_type, $is_usdt_wallet, $is_irr_wallet);
        $market_name = strtoupper($this->defineMarketName($payment_type));

        $incomeAmount = 0;
        $wage_type = 'unknown';
        $type = OrdersList::MARKET_TYPE;

        switch ($role) {
            case OrdersList::BUY_ROLE:
                if (strpos($market_name, 'IRT') !== false) {
                    $wage_type = 'IRR';
                    $incomeAmount = $this->order_wage_fee_irt;
                } elseif (strpos($market_name, 'USDT') !== false) {
                    $wage_type = 'USDT';
                    $incomeAmount = $this->order_wage_fee_usdt;
                }
                break;

            case OrdersList::SELL_ROLE:
                if ($this->isUsdt())
                    $wage_type = "USDT";
                else
                    $wage_type = str_replace(['IRT', 'USDT'], '', $market_name);
                $incomeAmount = $this->order_wage_qty;
                break;
        }


        Income::store($role, $type, $incomeAmount, strtoupper($wage_type));
    }


    /**
     * Pay commission for a specific trading pair.
     *
     * @param object $request The request object containing information about the trading pair.
     *
     * @return void
     */
    private function payCommision()
    {
        if ($this->isUsdt())
            $currency = ExchangeList::findBySymbol($this->market);
        else
            $currency = ExchangeList::findBySymbol(strtoupper(str_replace(['USDT', 'IRT'], '', $this->market)));
        $commissionAmount = 0.010 * ($this->order_wage_fee_usdt * $this->tether_price);
        event(new pay_commision($this->user, $commissionAmount, $currency->id));
    }

    private function increaseBtzWalletAmount($user, $total_value_irt, $multiplier)
    {
        switch (true) {
            case ($total_value_irt == 100000000):
                $this->userWalletsRepository::increaseWalletAmount($user->id, 'BTZ', 10 * $multiplier);
                break;
            case ($total_value_irt == 10000000):
                $this->userWalletsRepository::increaseWalletAmount($user->id, 'BTZ', 1 * $multiplier);
                break;
            case ($total_value_irt > 100000000 && $total_value_irt < 500000000):
            case ($total_value_irt > 10000000 && $total_value_irt < 100000000):
                $incremental_amount = $total_value_irt / 10000000;
                $incremental_amount = floor($incremental_amount);
                $this->userWalletsRepository::increaseWalletAmount($user->id, 'BTZ', $incremental_amount * $multiplier);
                break;
            case ($total_value_irt > 500000000 && $total_value_irt < 1000000000):
                $incremental_amount = $total_value_irt / 10000000;
                $incremental_amount = floor($incremental_amount);
                $this->userWalletsRepository::increaseWalletAmount($user->id, 'BTZ', $incremental_amount * $multiplier);
                break;
            case ($total_value_irt > 1000000000 && $total_value_irt < 3000000000):
                $incremental_amount = $total_value_irt / 10000000;
                $incremental_amount = floor($incremental_amount);
                $this->userWalletsRepository::increaseWalletAmount($user->id, 'BTZ', $incremental_amount * $multiplier);
                break;
            case ($total_value_irt > 3000000000):
                $incremental_amount = $total_value_irt / 10000000;
                $incremental_amount = floor($incremental_amount);
                $this->userWalletsRepository::increaseWalletAmount($user->id, 'BTZ', $incremental_amount * $multiplier);
                break;
        }
    }


    private function handleBtzIncrease()
    {
        switch ($this->wage_percent) {
            case '0.25':
                $this->increaseBtzWalletAmount($this->user, $this->total_value_irt, 1);
                break;
            case '0.22':
                $this->increaseBtzWalletAmount($this->user, $this->total_value_irt, 2);
                break;
            case '0.2':
                $this->increaseBtzWalletAmount($this->user, $this->total_value_irt, 3);
                break;
            case '0.175':
                $this->increaseBtzWalletAmount($this->user, $this->total_value_irt, 4);
                break;
            case '0.15':
                $this->increaseBtzWalletAmount($this->user, $this->total_value_irt, 5);
                break;
        }
    }


    /**
     * Build an invoice based on the specified payment method and user details.
     *
     * @param User $user The user for whom the invoice is being generated.
     * @param string|null $paymentUrl The payment URL for online payments; null for "wallet" payments.
     * @param string $paymentType The payment type, either 'online' or 'wallet'.
     * @param float $totalTether The total amount in Tether (cryptocurrency).
     * @param float $tetherPrice The current price of one Tether.
     * @param float $wage The wage percentage.
     * @param float $takerFeeRateShow The network wage percentage.
     * @param float $tomanAmount The equivalent amount in Toman for online payments.
     * @param float $orderAmount The amount of cryptocurrency coins being ordered.
     * @param string $pairType The type of cryptocurrency pair.
     * @param string $currencyTypeWagePercent The currency type for wage percentage (e.g., 'تتر').
     * @param string $currencyTypeWagePercentEn The currency type for wage percentage in English (e.g., 'tether').
     * @param string $currencyType The currency type for the total amount and receipt (e.g., 'تتر').
     * @param string $currencyTypeEn The currency type for the total amount and receipt in English (e.g., 'tether').
     * @param string $currencyTypeReceipt The currency type for the user's receipt (e.g., 'تومان').
     * @param string $currencyTypeReceiptEn The currency type for the user's receipt in English (e.g., 'toman').
     * @param string $paymentTypeEn The payment type in English (e.g., 'online' or 'wallet').
     *
     * @return array The generated invoice with payment details.
     */
    private function buildInvoice($yourPayment, $wagePercent, $networkWage, $currencyTypeWagePercent, $currencyTypeWagePercentEn, $yourReceipt, $currencyTypeYourReceipt, $currencyType, $currencyTypeEn, $paymentType, $paymentUrl = '')
    {
        $invoice = [
            'your_payment' => $yourPayment,
            'wage_percent' => $wagePercent,
            'network_wage' => $networkWage,
            'currency_type_wage_percent' => $currencyTypeWagePercent,
            'currency_type_wage_percent_en' => $currencyTypeWagePercentEn,
            'your_receipt' => $yourReceipt,
            'currency_type_your_receipt' => $currencyTypeYourReceipt,
            'currency_type' => $currencyType,
            'currency_type_en' => $currencyTypeEn,
            'payment_type' => $paymentType,
        ];

        if (!is_null($paymentUrl)) {
            $invoice['payment_url'] = $paymentUrl;
        }

        return $invoice;
    }

    private function sendSuccessfulWalletNotification($reduced_wallet, $increased_wallet)
    {
        $userId = $this->user->id;

        $freeze_amount1 = FreezeHelper::getFreezeAmount($reduced_wallet->wallet);
        //$info1 = $this->coinexService->getCryptoPairDetails($reduced_wallet->wallet . 'USDT');
        if ($reduced_wallet->amount - $freeze_amount1 == 0) {
            $value1 = 0;
            $toman_amount1 = 0;
        } else {
            switch ($reduced_wallet->wallet) {
                default:
                    $value1 = $reduced_wallet->amount * $this->coinex_market_info->last;
                    $toman_amount1 = $value1 * $this->tether_price;
                    break;
                case 'USDT':
                    $value1 = $reduced_wallet->amount * $this->tether_price;
                    $toman_amount1 = $value1;
                    break;
                case 'IRR':
                    $value1 = $reduced_wallet->amount;
                    $toman_amount1 = $value1;
                    break;
            }
        }

        $messageData = [
            'type' => NotificationHelper::WALLET,
            "id" => $reduced_wallet->id,
            "amount" => $reduced_wallet->amount,
            "freeze" => $freeze_amount1,
            "value" => $value1,
            "toman_amount" => $toman_amount1
        ];
        //send realtime notification to user
        $this->notificationHelper->sendNotification($userId, $messageData);

        // Send wallet relatime notification
        Cache::forget('wallet_list_' . $userId);

        unset($messageData);
        $freeze_amount2 = FreezeHelper::getFreezeAmount($increased_wallet->wallet);
        //$info2 = $this->coinexService->getCryptoPairDetails($reduced_wallet->wallet . 'USDT');
        if ($increased_wallet->amount - $freeze_amount2 == 0) {
            $value2 = 0;
            $toman_amount2 = 0;
        } else {
            switch ($increased_wallet->wallet) {
                default:
                    $value2 = $increased_wallet->amount * $this->coinex_market_info->last;
                    $toman_amount2 = $value2 * $this->tether_price;
                    break;
                case 'USDT':
                    $value2 = $increased_wallet->amount * $this->tether_price;
                    $toman_amount2 = $value2;
                    break;
                case 'IRR':
                    $value2 = $increased_wallet->amount;
                    $toman_amount2 = $value2;
                    break;
            }
        }
    }

    private function sendSuccessfulNotification($title, $message)
    {
        $userId = $this->user->id;
        $messageData = [
            'type' => NotificationHelper::NOTIFICATION,
            'title' => $title,
            'content' => trans($message),
        ];
        $this->notificationHelper->sendNotification($userId, $messageData);
    }

    private function sendSuccessfulOrderNotification()
    {

        switch ($this->order_info->role) {
            case OrdersList::BUY_ROLE:
                $role = OrdersList::BUY_ROLE;
                break;
            case OrdersList::SELL_ROLE:
                $role = OrdersList::SELL_ROLE;
                break;
        }

        $this->definePriceType($this->order_info, $role);
        $this->defineOrderPrice($this->order_info, $role);


        $userId = $this->user->id;
        $messageData = [
            'type' => NotificationHelper::ORDER,
            'id' => $this->order_info->id,
            'time' => $this->order_info->time,
            'market' => $this->order_info->market,
            'order_type' => $this->order_info->type,
            'role' => $this->order_info->role,
            'order_price' => $this->order_price, // new
            'order_price_type' => $this->order_price_type, // new
            'avg_price' => $this->order_info->avg_price,
            'amount' => $this->order_info->amount,
            'total_price' => $this->total_price, // new
            'total_price_type' => $this->total_price_type, // new
            'current_wage' => $this->order_info->current_wage,
            'currency_price' => $this->order_info->currency_price,
            'filled' => $this->order_info->filled,
            'status' => $this->order_info->status,
        ];
        $this->notificationHelper->sendNotification($userId, $messageData);
    }

    private function storeDoneOrder($trade_type)
    {
        if ($trade_type == 'virtual')
            $this->coinex_order_result = null;

        $done_order_info = [
            'order_id' => $this->order_info->id,
            'amount' => @$this->coinex_order_result->amount,
            'asset_fee' => @$this->coinex_order_result->asset_fee,
            'avg_price' => @$this->coinex_order_result->avg_price,
            'client_id' => @$this->coinex_order_result->client_id,
            'create_time' => @$this->coinex_order_result->create_time,
            'deal_amount' => @$this->coinex_order_result->deal_amount,
            'deal_fee' => @$this->coinex_order_result->deal_fee,
            'deal_money' => @$this->coinex_order_result->deal_money,
            'fee_asset' => @$this->coinex_order_result->fee_asset,
            'fee_discount' => @$this->coinex_order_result->fee_discount,
            'finished_time' => @$this->coinex_order_result->finished_time,
            'identifier' => @$this->coinex_order_result->id,
            'left' => @$this->coinex_order_result->left,
            'maker_fee_rate' => @$this->coinex_order_result->maker_fee_rate,
            'market' => @$this->coinex_order_result->market,
            'money_fee' => @$this->coinex_order_result->money_fee,
            'order_type' => @$this->coinex_order_result->order_type,
            'price' => @$this->coinex_order_result->price,
            'status' => @$this->coinex_order_result->status,
            'stock_fee' => @$this->coinex_order_result->stock_fee,
            'taker_fee_rate' => @$this->coinex_order_result->taker_fee_rate,
            'type' => @$this->coinex_order_result->type,
            'trade_type' => $trade_type,
        ];

        return DoneOrdersList::store($done_order_info);
    }

    private function storeOrderInfo($type, $role, $trade_type, $status = OrdersList::DONE_STATUS)
    {

        // Detect order type (ex: market or limit)
        switch ($type) {

            case OrdersList::MARKET_TYPE: // Market type

                // Detect order role (ex: buy or sell)
                switch ($role) {

                    case OrdersList::BUY_ROLE:

                        // Define payment type (ex: IRR or USDT)
                        $is_usdt_wallet = $this->request->usdt_wallet ? true : false;
                        $is_irr_wallet = $this->request->irr_wallet ? true : false;

                        $payment_type = self::detectPaymentType($this->request->amount_type, $this->request->pair_type, $is_usdt_wallet, $is_irr_wallet);

                        // Define market name (ex: DOGE or IRT or USDT)
                        $market_name = strtoupper($this->defineMarketName($payment_type));

                        $order_info = [
                            'coinex_identifier' => ($this->isUsdt()) ? 1111 : $this->coinex_order_result->id,
                            'user_id' => $this->user->id,
                            'time' => now(),
                            'market' => strtoupper($market_name),
                            'type' => $type,
                            'role' => $role,

                            'usdt_price' => $this->coinex_market_info->last, // new
                            'total_usdt_price' => $this->total_value_usdt, // new
                            'irt_price' => $this->coinex_market_info->last * $this->tether_price, // new
                            'total_irt_price' => $this->total_value_irt, // new

                            'order_price' => $this->total_value_usdt, //obsolote
                            'avg_price' => 0,
                            'amount' => $this->order_qty,
                            'total_price' => $this->total_value_usdt,  //obsolote
                            'current_wage' => $this->order_wage_fee_usdt,
                            'toman_wage' => $this->order_wage_fee_irt,
                            'currency_price' => $this->coinex_market_info->last, //obsolote
                            'tether_price' => $this->tether_price,
                            'payment_type' => strtoupper($payment_type),
                            'received_type' => strtoupper($this->request->pair_type),
                            'filled' => OrdersList::FILLED,
                            'trade_type' => $trade_type,

                            'order_value_usdt' => $this->order_value_usdt,
                            'order_value_irt' => $this->order_value_irt,
                            'order_value_coinex_usdt' => $this->order_value_coinex_usdt,
                            'order_value_coinex_irt' => $this->order_value_coinex_irt,
                            'order_wage_qty' => $this->order_wage_qty,
                            'order_network_wage_qty' => $this->order_network_wage_qty,
                            'order_qty_coinex' => $this->order_qty_coinex,
                            'order_wage_fee_usdt' => $this->order_wage_fee_usdt,
                            'order_network_wage_fee_usdt' => $this->order_network_wage_fee_usdt,
                            'order_total_fee_usdt' => $this->order_total_fee_usdt,
                            'order_wage_fee_irt' => $this->order_wage_fee_irt,
                            'order_network_wage_fee_irt' => $this->order_network_wage_fee_irt,
                            'order_total_fee_irt' => $this->order_total_fee_irt,
                            'total_freezed' => 0,
                            'total_freezed_asset' => strtoupper($payment_type),

                            'max_qty_user' => $this->max_qty_user,
                            'max_usdt_user' => $this->max_usdt_user,
                            'max_irt_user' => $this->max_irt_user,
                            'filled_qty' => $this->filled_qty,
                            'filled_usdt' => $this->filled_usdt,
                            'filled_irt' => $this->filled_irt,

                            'is_wage_reducted' => 1,

                            'status' => $status,
                        ];

                        return OrderRepository::storeBuyOrders($order_info);

                    case OrdersList::SELL_ROLE:

                        // Define receipt type (ex: IRR or USDT)
                        $receipt_type = self::detectReceiptType($this->request->receipt_type, $this->request->pair_type);

                        // Define market name (ex: DOGEUDT or DOGEIRT)
                        $market_name = strtoupper($this->defineMarketName($receipt_type));

                        $order_info = [
                            'coinex_identifier' => ($this->isUsdt()) ? null : $this->coinex_order_result->id,
                            'user_id' => $this->user->id,
                            'time' => now(),
                            'market' => $market_name,
                            'type' => $type,
                            'role' => $role,

                            'usdt_price' => $this->coinex_market_info->last, // new
                            'total_usdt_price' => $this->total_value_usdt, // new
                            'irt_price' => $this->coinex_market_info->last * $this->tether_price, // new
                            'total_irt_price' => $this->total_value_irt, // new

                            'order_price' => $this->total_value_usdt,
                            'avg_price' => 0,
                            'amount' => $this->order_qty,
                            'total_price' => $this->total_value_usdt,
                            'current_wage' => $this->order_wage_fee_usdt,
                            'toman_wage' => $this->order_wage_fee_irt,
                            'currency_price' => $this->coinex_market_info->last,
                            'tether_price' => $this->tether_price,
                            'payment_type' => strtoupper($this->request->pair_type),
                            'received_type' => strtoupper($receipt_type),
                            'filled' => OrdersList::FILLED,
                            'trade_type' => $trade_type,

                            'order_value_usdt' => $this->order_value_usdt,
                            'order_value_irt' => $this->order_value_irt,
                            'order_value_coinex_usdt' => $this->order_value_coinex_usdt,
                            'order_value_coinex_irt' => $this->order_value_coinex_irt,
                            'order_wage_qty' => $this->order_wage_qty,
                            'order_network_wage_qty' => $this->order_network_wage_qty,
                            'order_qty_coinex' => $this->order_qty_coinex,
                            'order_wage_fee_usdt' => $this->order_wage_fee_usdt,
                            'order_network_wage_fee_usdt' => $this->order_network_wage_fee_usdt,
                            'order_total_fee_usdt' => $this->order_total_fee_usdt,
                            'order_wage_fee_irt' => $this->order_wage_fee_irt,
                            'order_network_wage_fee_irt' => $this->order_network_wage_fee_irt,
                            'order_total_fee_irt' => $this->order_total_fee_irt,
                            'total_freezed' => 0,
                            'total_freezed_asset' => strtoupper($this->request->pair_type),

                            'max_qty_user' => $this->max_qty_user,
                            'max_usdt_user' => $this->max_usdt_user,
                            'max_irt_user' => $this->max_irt_user,
                            'filled_qty' => $this->filled_qty,
                            'filled_usdt' => $this->filled_usdt,
                            'filled_irt' => $this->filled_irt,

                            'is_wage_reducted' => 1,

                            'status' => $status,
                        ];

                        return OrderRepository::storeSellOrders($order_info);
                }

                break;

            case OrdersList::LIMIT_TYPE: // Limit type

                // Detect order role (ex: buy or sell)
                switch ($role) {

                    case OrdersList::BUY_ROLE:

                        // Define payment type (ex: IRR or USDT)

                        $is_usdt_wallet = $this->request->usdt_wallet ? true : false;
                        $is_irr_wallet = $this->request->irr_wallet ? true : false;

                        $payment_type = self::detectPaymentType($this->request->amount_type, $this->request->pair_type, $is_usdt_wallet, $is_irr_wallet);

                        // Define market name (ex: DOGEUDT or DOGEIRT)
                        $market_name = strtoupper($this->defineMarketName($payment_type));

                        $order_info = [
                            'coinex_identifier' => $this->coinex_order_result->id,
                            'user_id' => $this->user->id,
                            'time' => now(),
                            'market' => $market_name,
                            'type' => $type,
                            'role' => $role,

                            'usdt_price' => $this->pair_price, // pair price in usdt
                            'total_usdt_price' => $this->total_value_usdt, // new
                            'irt_price' => ($this->request->amount_type == 'toman') ? $this->request->pair_price : $this->request->pair_price * $this->tether_price, // new
                            'total_irt_price' => $this->total_value_irt, // new

                            'order_price' => $this->total_value_usdt,
                            'avg_price' => 0,
                            'amount' => $this->order_qty,
                            'total_price' => $this->total_value_usdt,
                            'current_wage' => $this->order_wage_fee_usdt,
                            'toman_wage' => $this->order_wage_fee_irt,
                            'currency_price' => $this->pair_price, //pair_price in usdt
                            'tether_price' => $this->tether_price,
                            'payment_type' => $payment_type,
                            'received_type' => strtoupper($this->request->pair_type),
                            'filled' => 0,
                            'trade_type' => $trade_type,

                            'order_value_usdt' => $this->order_value_usdt,
                            'order_value_irt' => $this->order_value_irt,
                            'order_value_coinex_usdt' => $this->order_value_coinex_usdt,
                            'order_value_coinex_irt' => $this->order_value_coinex_irt,
                            'order_wage_qty' => $this->order_wage_qty,
                            'order_network_wage_qty' => $this->order_network_wage_qty,
                            'order_qty_coinex' => $this->order_qty_coinex,
                            'order_wage_fee_usdt' => $this->order_wage_fee_usdt,
                            'order_network_wage_fee_usdt' => $this->order_network_wage_fee_usdt,
                            'order_total_fee_usdt' => $this->order_total_fee_usdt,
                            'order_wage_fee_irt' => $this->order_wage_fee_irt,
                            'order_network_wage_fee_irt' => $this->order_network_wage_fee_irt,
                            'order_total_fee_irt' => $this->order_total_fee_irt,
                            'total_freezed' => ($this->request->amount_type == 'toman') ? $this->total_value_irt : $this->total_value_usdt,
                            'total_freezed_asset' => $payment_type,

                            'max_qty_user' => $this->max_qty_user,
                            'max_usdt_user' => $this->max_usdt_user,
                            'max_irt_user' => $this->max_irt_user,
                            'filled_qty' => $this->filled_qty,
                            'filled_usdt' => $this->filled_usdt,
                            'filled_irt' => $this->filled_irt,

                            'is_wage_reducted' => 0,

                            'status' => $status
                        ];

                        return OrderRepository::storeBuyOrders($order_info);

                    case OrdersList::SELL_ROLE:

                        // Define receipt type (ex: IRR or USDT)
                        $receipt_type = self::detectReceiptType($this->request->amount_type);

                        // Define market name (ex: DOGEUDT or DOGEIRT)
                        $market_name = strtoupper($this->defineMarketName($receipt_type));

                        $order_info = [
                            'coinex_identifier' => $this->coinex_order_result->id,
                            'user_id' => $this->user->id,
                            'time' => now(),
                            'market' => $market_name,
                            'type' => $type,
                            'role' => $role,

                            'usdt_price' => $this->pair_price, // new
                            'total_usdt_price' => $this->total_value_usdt, // new
                            'irt_price' => ($this->request->amount_type == 'toman') ? $this->request->pair_price : $this->request->pair_price * $this->tether_price, // new
                            'total_irt_price' => $this->total_value_irt,

                            'order_price' => $this->total_value_usdt,
                            'avg_price' => 0,
                            'amount' => $this->order_qty,
                            'total_price' => $this->total_value_usdt,
                            'current_wage' => $this->order_wage_fee_usdt,
                            'toman_wage' => $this->order_wage_fee_irt,
                            'currency_price' => $this->pair_price,
                            'tether_price' => $this->tether_price,
                            'payment_type' => strtoupper($this->request->pair_type),
                            'received_type' => $receipt_type,
                            'filled' => 0,
                            'trade_type' => $trade_type,

                            'order_value_usdt' => $this->order_value_usdt,
                            'order_value_irt' => $this->order_value_irt,
                            'order_value_coinex_usdt' => $this->order_value_coinex_usdt,
                            'order_value_coinex_irt' => $this->order_value_coinex_irt,
                            'order_wage_qty' => $this->order_wage_qty,
                            'order_network_wage_qty' => $this->order_network_wage_qty,
                            'order_qty_coinex' => $this->order_qty_coinex,
                            'order_wage_fee_usdt' => $this->order_wage_fee_usdt,
                            'order_network_wage_fee_usdt' => $this->order_network_wage_fee_usdt,
                            'order_total_fee_usdt' => $this->order_total_fee_usdt,
                            'order_wage_fee_irt' => $this->order_wage_fee_irt,
                            'order_network_wage_fee_irt' => $this->order_network_wage_fee_irt,
                            'order_total_fee_irt' => $this->order_total_fee_irt,
                            'total_freezed' => $this->order_qty,
                            'total_freezed_asset' => strtoupper($this->request->pair_type),

                            'max_qty_user' => $this->max_qty_user,
                            'max_usdt_user' => $this->max_usdt_user,
                            'max_irt_user' => $this->max_irt_user,
                            'filled_qty' => $this->filled_qty,
                            'filled_usdt' => $this->filled_usdt,
                            'filled_irt' => $this->filled_irt,

                            'is_wage_reducted' => 0,

                            'status' => $status
                        ];
                        return OrderRepository::storeSellOrders($order_info);
                }
                break;
        }
    }

    private function storeOrderLog($type, $role, $trade_type)
    {

        // Detect order type (ex: market or limit)
        switch ($type) {

            case OrdersList::MARKET_TYPE: // Market type

                switch ($role) {

                    case OrdersList::BUY_ROLE:

                        // Define payment type (ex: IRR or USDT)
                        $is_usdt_wallet = $this->request->usdt_wallet ? true : false;
                        $is_irr_wallet = $this->request->irr_wallet ? true : false;

                        $payment_type = self::detectPaymentType($this->request->amount_type, $this->request->pair_type, $is_usdt_wallet, $is_irr_wallet);

                        // Define market name (ex: DOGEUDT or DOGEIRT)
                        $market_name = $this->defineMarketName($payment_type);

                        $order_log = [
                            'user_id' => $this->user->id,
                            'market' => $market_name,
                            'type' => $type,
                            'role' => $role,

                            'usdt_price' => ($this->isUsdt()) ? 1 : $this->coinex_market_info->last, // new
                            'total_usdt_price' => $this->total_value_usdt, // new
                            'irt_price' => ($this->isUsdt()) ? $this->tether_price : $this->coinex_market_info->last * $this->tether_price, // new
                            'total_irt_price' => $this->total_value_irt, // new

                            'order_price' => ($this->isUsdt()) ? 1 : $this->total_value_usdt,
                            'avg_price' => 0,

                            'amount' => ($this->isUsdt()) ? $this->request->pair_amount : $this->order_qty,
                            'total_price' => $this->total_value_irt,
                            'current_wage' => $this->order_wage_fee_usdt,
                            'toman_wage' => $this->tether_price * $this->order_wage_fee_usdt,
                            'currency_price' => ($this->isUsdt()) ? 1 : $this->coinex_market_info->last,
                            'tether_price' => $this->tether_price,
                            'trade_type' => $trade_type,
                            'filled' => 0,
                            'status' => OrdersList::CANCEL_STATUS,
                            'request_values' => json_encode($this->coinex_order_info),
                            'exchange_error' => $this->coinex_order_result->error
                        ];

                        OrdersLog::storeLog($order_log);
                        break;

                    case OrdersList::SELL_ROLE:

                        // Define receipt type (ex: IRR or USDT)
                        $receipt_type = self::detectReceiptType($this->request->amount_type, $this->request->pair_type);

                        // Define market name (ex: DOGEUDT or DOGEIRT)
                        $market_name = $this->defineMarketName($receipt_type);

                        $order_log = [
                            'user_id' => $this->user->id,
                            'market' => $market_name,
                            'type' => $type,
                            'role' => $role,

                            'usdt_price' => $this->coinex_market_info->last, // new
                            'total_usdt_price' => $this->total_value_usdt, // new
                            'irt_price' => $this->coinex_market_info->last * $this->tether_price, // new
                            'total_irt_price' => $this->total_value_usdt * $this->tether_price, // new

                            'order_price' => $this->total_value_irt,
                            'avg_price' => 0,
                            'amount' => $this->request->pair_amount,
                            'total_price' => $this->total_value_irt,
                            'current_wage' => $this->order_wage_fee_usdt,
                            'toman_wage' => $this->tether_price * $this->order_wage_fee_usdt,
                            'currency_price' => $this->coinex_market_info->last,
                            'tether_price' => $this->tether_price,
                            'trade_type' => $trade_type,
                            'filled' => 0,
                            'status' => OrdersList::CANCEL_STATUS,
                            'request_values' => json_encode($this->coinex_order_info),
                            'exchange_error' => $this->coinex_order_result->error
                        ];

                        OrdersLog::storeLog($order_log);
                        break;
                }
                break;

            case OrdersList::LIMIT_TYPE:

                switch ($role) {

                    case OrdersList::BUY_ROLE:

                        // Define payment type (ex: IRR or USDT)
                        $is_usdt_wallet = $this->request->usdt_wallet ? true : false;
                        $is_irr_wallet = $this->request->irr_wallet ? true : false;

                        $payment_type = self::detectPaymentType($this->request->amount_type, $this->request->pair_type, $is_usdt_wallet, $is_irr_wallet);

                        // Define market name (ex: DOGEUDT or DOGEIRT)
                        $market_name = $this->defineMarketName($payment_type);

                        $order_log = [
                            'user_id' => $this->user->id,
                            'market' => $market_name,
                            'type' => $type,
                            'role' => $role,

                            'usdt_price' => $this->request->pair_price / $this->tether_price, // new
                            'total_usdt_price' => $this->total_value_usdt, // new
                            'irt_price' => $this->request->pair_price, // new
                            'total_irt_price' => $this->request->pair_price * $this->order_qty, // new

                            'order_price' => $this->total_value_usdt,
                            'avg_price' => 0,
                            'amount' => $this->request->pair_amount,
                            'total_price' => $this->total_value_irt,
                            'current_wage' => $this->order_wage_fee_usdt,
                            'toman_wage' => $this->tether_price * $this->order_wage_fee_usdt,
                            'currency_price' => $this->request->pair_price,
                            'tether_price' => $this->tether_price,
                            'trade_type' => $trade_type,
                            'filled' => 0,
                            'status' => OrdersList::CANCEL_STATUS,
                            'request_values' => json_encode($this->coinex_order_info),
                            'exchange_error' => $this->coinex_order_result->error
                        ];

                        OrdersLog::storeLog($order_log);

                        break;

                    case OrdersList::SELL_ROLE:

                        // Define receipt type (ex: IRR or USDT)
                        $receipt_type = self::detectReceiptType($this->request->amount_type, $this->request->pair_type);

                        // Define market name (ex: DOGEUDT or DOGEIRT)
                        $market_name = $this->defineMarketName($receipt_type);

                        $order_log = [
                            'user_id' => $this->user->id,
                            'market' => $market_name,
                            'type' => $type,
                            'role' => $role,

                            'usdt_price' => $this->request->pair_price / $this->tether_price, // new
                            'total_usdt_price' => $this->total_value_usdt, // new
                            'irt_price' => $this->request->pair_price, // new
                            'total_irt_price' => $this->request->pair_price * $this->order_qty, // new

                            'order_price' => $this->total_value_irt,
                            'avg_price' => 0,
                            'amount' => $this->request->pair_amount,
                            'total_price' => $this->total_value_irt,
                            'current_wage' => $this->order_wage_fee_usdt,
                            'toman_wage' => $this->tether_price * $this->order_wage_fee_usdt,
                            'currency_price' => $this->request->pair_price,
                            'tether_price' => $this->tether_price,
                            'filled' => 0,
                            'status' => OrdersList::CANCEL_STATUS,
                            'request_values' => json_encode($this->coinex_order_info),
                            'exchange_error' => $this->coinex_order_result->error
                        ];

                        OrdersLog::storeLog($order_log);

                        break;
                }
                break;
        }
    }

    private function storeMongoTradeAmount()
    {
        if ($this->isUsdt())
            $currency = "USDT";
        else
            $currency = str_replace('USDT', '', $this->market);

        $new_trade_amount = [
            'user_id' => $this->user->id,
            'done_order_id' => ($this->isUsdt()) ? 1111 : $this->done_order->id,
            'order_id' => $this->order_info->id,
            'currency' => $currency,
            'price' => $this->total_value_irt,
        ];
        MongoTradeAmount::store($new_trade_amount);
    }

    private function sendCoinexMarketOrder($role)
    {

        switch ($role) {

            case OrdersList::BUY_ROLE: // Buy order

                $coinex_order_info = [
                    'access_id' => $this->coinex_access_id,
                    'market' => strtoupper($this->market),
                    'type' => 'buy',
                    'amount' => number_format($this->order_value_coinex_usdt, $this->pricing_decimal, '.', ''),
                    'tonce' => now()->toDateTimeString(),
                    'account_id' => 0,
                    'client_id' => @$this->user->mobile
                ];
                $this->coinex_order_info = $coinex_order_info;

                $this->coinex_order_result = BuyExchangeTrait::coinexBuyOrder($coinex_order_info, 'market');

                break;

            case OrdersList::SELL_ROLE: // Sell order

                $coinex_order_info = [
                    'access_id' => $this->coinex_access_id,
                    'market' => strtoupper($this->market),
                    'type' => 'sell',
                    'amount' => number_format($this->order_qty_coinex, $this->trading_decimal, '.', ''),
                    'tonce' => now()->toDateTimeString(),
                    'account_id' => 0,
                    'client_id' => @$this->user->mobile
                ];
                $this->coinex_order_info = $coinex_order_info;

                $this->coinex_order_result = SellExchangeTrait::SellOrderCoinex($coinex_order_info, 'market');

                break;
        }
    }

    private function sendCoinexLimitOrder($role)
    {
        switch ($role) {

            case OrdersList::BUY_ROLE: // Buy order

                $coinex_order_info = [
                    'access_id' => $this->coinex_access_id,
                    'market' => strtoupper($this->market),
                    'type' => 'buy',
                    'amount' => number_format($this->order_qty_coinex, $this->trading_decimal, '.', ''),
                    'price' => number_format($this->pair_price, $this->pricing_decimal, '.', ''),
                    'tonce' => now()->toDateTimeString(),
                    'account_id' => 0,
                    'client_id' => @$this->user->mobile
                ];
                $this->coinex_order_info = $coinex_order_info;

                $this->coinex_order_result = BuyExchangeTrait::coinexBuyOrder($coinex_order_info, 'limit');

                break;

            case OrdersList::SELL_ROLE: // Sell order

                $coinex_order_info = [
                    'access_id' => $this->coinex_access_id,
                    'market' => strtoupper($this->market),
                    'type' => 'sell',
                    'amount' => number_format($this->order_qty_coinex, $this->trading_decimal, '.', ''),
                    'price' => number_format($this->pair_price, $this->pricing_decimal, '.', ''),
                    'tonce' => now()->toDateTimeString(),
                    'account_id' => 0,
                    'client_id' => @$this->user->mobile
                ];
                $this->coinex_order_info = $coinex_order_info;

                $this->coinex_order_result = SellExchangeTrait::SellOrderCoinex($coinex_order_info, 'limit');

                break;
        }
    }

    private function processCoinexOrder($type, $role, $trade_type)
    {


        $order_setting = OrderSetting::checkOrderMode();
        $this->coinex_order_result = 0;
        //if market order is done automatically by the system
        if ($order_setting->mode == OrderSetting::AUTOMATIC_MODE) {

            switch ($type) {
                case OrdersList::MARKET_TYPE:

                    if (@$this->currency_setting->type == 1 || !$this->currency_setting || (@$this->currency_setting->type == 2 && (@$this->currency_setting->loss > $this->coinex_market_info->last)))
                        $this->sendCoinexMarketOrder($role);

                    break;

                case OrdersList::LIMIT_TYPE:

                    switch ($role) {
                        case OrdersList::BUY_ROLE:

                            if (@$this->currency_setting->type == 1 || !$this->currency_setting)
                                $this->sendCoinexLimitOrder($role);

                            break;
                        case OrdersList::SELL_ROLE:

                            $this->sendCoinexLimitOrder($role);

                            break;
                    }
                    break;
            }
        } else {

            $this->order_info = $this->storeOrderInfo($type, $role, $trade_type, OrdersList::PENDING_STATUS);
        }
    }

    private function handleSuccessfulOrder($trade_type, $payment_type, $role)
    {

        // update wallet amount
        switch ($role) {

            case OrdersList::BUY_ROLE: // Buy order
                switch ($payment_type) {

                    case OrdersList::IRR_PAYMENT:
                        // When user have toman to buy currency
                        $reduced_wallet = $this->userWalletsRepository
                            ->decreaseWalletAmount($this->user->id, 'IRR', $this->total_value_irt);

                        $increased_wallet = $this->userWalletsRepository
                            ->increaseWalletAmount($this->user->id, strtoupper($this->request->pair_type), ($this->max_qty_user));

                        break;

                    case OrdersList::USDT_PAYMENT:
                        // When user have usdt to buy currency
                        $reduced_wallet = $this->userWalletsRepository
                            ->decreaseWalletAmount($this->user->id, 'USDT', $this->total_value_usdt);

                        $increased_wallet = $this->userWalletsRepository
                            ->increaseWalletAmount($this->user->id, strtoupper($this->request->pair_type), ($this->max_qty_user));

                        break;
                }
                break;

            case OrdersList::SELL_ROLE: // Sell order

                $reduced_wallet = $this->userWalletsRepository
                    ->decreaseWalletAmount($this->user->id, strtoupper($this->request->pair_type), $this->order_qty);

                // Increase user wallet based on user specified receipt type
                switch (strtolower($this->request->receipt_type)) {
                    case 'usdt':
                        $increased_wallet = $this->userWalletsRepository
                            ->increaseWalletAmount($this->user->id, 'USDT', $this->max_usdt_user); // DO NOT use total value usdt since it has fee too and we don't  want to give back fee to user
                        break;
                    case 'toman':
                    default:
                        $increased_wallet = $this->userWalletsRepository
                            ->increaseWalletAmount($this->user->id, 'IRR', $this->max_irt_user);
                        break;
                }
        }

        // Send wallet relatime notification
        // $this->sendSuccessfulWalletNotification($reduced_wallet, $increased_wallet);

        // Store order Info
        switch ($role) {
            case OrdersList::BUY_ROLE:
                $this->order_info = $this->storeOrderInfo(OrdersList::MARKET_TYPE, OrdersList::BUY_ROLE, $trade_type);
                break;
            case OrdersList::SELL_ROLE:
                $this->order_info = $this->storeOrderInfo(OrdersList::MARKET_TYPE, OrdersList::SELL_ROLE, $trade_type);
                break;
        }

        //if this is not usdt order then we store done order (since usdt doesn't send any request to coinex )
        if (!$this->isUsdt()) {

            //save coinex order identifier
            //if (isset($this->coinex_order_result) && is_object($this->coinex_order_result) && property_exists($this->coinex_order_result, 'id'))
            //   OrderRepository::saveCoinexOrderIdentifier($this->order_info->order_id, $this->coinex_order_result->id);

            // $this->sendSuccessfulOrderNotification();

            // Prepare coinex done order information (order_id is foreign key on orders_list table)
            $this->done_order = $this->storeDoneOrder($trade_type);

            $this->count = $this->doneOrdersCountComplete();

            //Add score and BTZ to user
            // $this->assignToUser();

            //BTZ for first user trade
            AssignFirstTradeBtzJob::dispatch($this->user, $this->count, $this->notificationRepository);
        }
        // TODO improve by new codes
        // Store daily income
        // $this->storeDailyIncome();

        //calculate and update site income
        StoreOrderIncomeJob::dispatch($this->request, $this->order_wage_fee_irt, $this->order_wage_fee_usdt, $this->order_wage_qty, $this->market, $role);

        // TODO improve by new codes
        //save income log
        // $this->storeIncomeLog($role);
        StoreIncomeLogJob::dispatch($role, $this->user, $this->order_info, $this->order_wage_fee_irt, $this->order_wage_fee_usdt, $this->order_wage_qty, $this->request, $this->market);

        //event(new OrderVolumenScore($this->user));

        //pay commission to referal users
        // $this->payCommision();
        PayCommissionJob::dispatch($this->market, $this->order_wage_fee_usdt, $this->tether_price, $this->user);

        // Allocate btz to user by trade amount
        // $this->handleBtzIncrease();
        HandleBtzIncreaseJob::dispatch($this->user, $this->total_value_irt, $this->wage_percent, $this->userWalletsRepository);

        // Send simple notification
        // $this->sendSimpleNotification($role);
        $this->notificationRepository::sendNotificationUpdateNotification($this->order_info);

        // Store trade amount
        $this->storeMongoTradeAmount();


        // Send wallet notification
        UserWalletsRepository::sendWalletUpdateNotificationOrder($this->order_info->id, $reduced_wallet, $increased_wallet);

        // $this->sendSuccessfulWalletNotification($reduced_wallet, $increased_wallet);

        // Send order realtime notification
        // $this->sendSuccessfulOrderNotification();
        $this->orderRepository::sendOrderUpdateNotification($this->order_info);


        // Detect order is buy or sell
        switch ($role) {
            case OrdersList::BUY_ROLE:
                // SmsHelper::sendMessage($this->user->mobile, $this->templates_id['market_order'], [$this->request->pair_amount, $this->request->pair_type]);
                // break;
                SmsJob::dispatch($this->user->mobile, $this->templates_id['market_order'], [$this->request->pair_amount, $this->request->pair_type]);
                break;

            case OrdersList::SELL_ROLE:
                // SmsHelper::sendMessage($this->user->mobile, $this->templates_id['sell_market_order'], [$this->request->pair_amount, $this->request->pair_type]);
                // break;
                SmsJob::dispatch($this->user->mobile, $this->templates_id['sell_market_order'], [$this->request->pair_amount, $this->request->pair_type]);
                break;
        }
    }

    private function logFreezeTransaction($amount, $currency, $identifier, $action, $name,)
    {

        $result = '1';
        if ($amount !== null) {
            $freeze_log = new FreezeLog();
            $freeze_log->identifier = $identifier;
            $freeze_log->amount = $amount;
            $freeze_log->currency = $currency;
            $freeze_log->action = $action;
            $freeze_log->name = $name;
            $freeze_log->result = $result;
            $freeze_log->save();
        } else {
            $freeze_log = new FreezeLog();
            $freeze_log->identifier = $identifier;
            $freeze_log->amount = $amount;
            $freeze_log->currency = $currency;
            $freeze_log->action = $action;
            $freeze_log->name = $name;
            $freeze_log->result = '-1';
            $freeze_log->save();
        }
    }


    private function handleSuccessfulLimitOrder($trade_type, $payment_type, $role, $is_done = false)
    {

        //if (!$is_done) {

        // limit order -  Update wallet and freeze amount for limit orders
        switch ($role) {

            case OrdersList::BUY_ROLE: // Buy order
                switch ($payment_type) {

                    case OrdersList::IRR_PAYMENT:
                        // When user have toman to buy currency
                        $freezed_wallet = $this->userWalletsRepository
                            ->freezeAmount($this->user->id, 'IRR', $this->total_value_irt);
                        $this->logFreezeTransaction($this->total_value_irt, 'IRR', $this->coinex_order_result->id, 'freezeAmount', 'coinex2-buy freeze');
                        break;
                    case OrdersList::USDT_PAYMENT:
                        // When user have usdt to buy currency
                        $freezed_wallet = $this->userWalletsRepository
                            ->freezeAmount($this->user->id, 'USDT', $this->total_value_usdt);
                        $this->logFreezeTransaction($this->total_value_usdt, 'USDT', $this->coinex_order_result->id, 'freezeAmount', 'coinex2-buy freeze');
                        break;
                }
                break;

            case OrdersList::SELL_ROLE: // Sell order
                $freezed_wallet = $this->userWalletsRepository
                    ->freezeAmount($this->user->id, strtoupper($this->request->pair_type), $this->order_qty);
                $this->logFreezeTransaction($this->order_qty, strtoupper($this->request->pair_type), $this->coinex_order_result->id, 'freezeAmount', 'coinex2-sell freeze');
        }
        //}



        $this->order_info = $this->storeOrderInfo(OrdersList::LIMIT_TYPE, $role, $trade_type, OrdersList::PENDING_STATUS);


        // Store done order - data that is returned by coinex api
        $this->done_order = $this->storeDoneOrder($trade_type);

        // Allocate btz to user by trade amount
        HandleBtzIncreaseJob::dispatch($this->user, $this->total_value_irt, $this->wage_percent, $this->userWalletsRepository);


        // Store order for trade in bitazar when trade type equal virtual
        if ($trade_type == OrdersList::VIRTUAL)
            $this->storeBitazarLimitOrders();


        // Check if this limit order has completed just immediately
        if ($is_done) {
            $this->handleSuccessfulLimitOrderDone($this->order_info, $this->done_order);
        } else {

            // Send wallet relatime notification
            UserWalletsRepository::sendWalletUpdateNotificationOrder($this->order_info->id, $freezed_wallet);

            // Send order realtime notification
            $this->orderRepository::sendOrderUpdateNotification($this->order_info);

            switch ($role) {
                case OrdersList::BUY_ROLE:
                    // SmsHelper::sendMessage($this->user->mobile, $this->templates_id['limit_order'], [$this->request->pair_amount, $this->request->pair_type]);
                    // break;
                    SmsJob::dispatch($this->user->mobile, $this->templates_id['limit_order'], [$this->request->pair_amount, $this->request->pair_type]);
                    break;
                case OrdersList::SELL_ROLE:
                    // SmsHelper::sendMessage($this->user->mobile, $this->templates_id['sell_limit_order'], [$this->request->pair_amount, $this->request->pair_type]);
                    // break;
                    SmsJob::dispatch($this->user->mobile, $this->templates_id['sell_limit_order'], [$this->request->pair_amount, $this->request->pair_type]);
                    break;
            }
        }
    }

    private function handleFailureOrder($trade_type, $role, $type)
    {

        $this->storeOrderLog($type, $role, $trade_type);

        //TODO send realtime notification to user

        // SmsHelper::sendMessage('09122335645', '74293', [$this->user->mobile . '' . $type, $this->coinex_order_result->error]);
        SmsJob::dispatch('09122335645', 74293, [$this->user->mobile . '' . $type, $this->coinex_order_result->error]);
    }

    private function doneOrdersCount()
    {
        return DoneOrdersList::getCount(@$this->coinex_order_result->client_id);
    }

    private function doneOrdersCountComplete()
    {
        return DoneOrdersList::getCountComplete(@$this->coinex_order_result->client_id);
    }

    private function checkTomanLimit(): bool
    {
        if ($this->total_value_irt > 100000000)
            return false;

        return true;
    }

    private function assignToUser()
    {

        if ($this->count == 1) {
            event(new Scoring($this->user, 420));
            $this->allocateBtzRewards(420);

            $title = 'allocate_fifty_btz_for_first_trade_title';
            $message = 'allocate_fifty_btz_for_first_trade_content';
            $this->notificationRepository::sendSuccessfulNotification($this->user->id, $title, $message);

            // Notification::send(User::find($this->user->id), new UserInviteNotification(User::find($this->user->id), 'allocate_fifty_btz_for_first_trade_content', 'allocate_fifty_btz_for_first_trade_title'));

            UserMessageNotificationJob::dispatch(User::find($this->user->id), $message, $title);
        }
    }

    private function calculateWagePercent($order_value_irt)
    {

         $past_thirty_days = MongoTradeAmount::pastThirtyDays($this->user->id);
         $sum_of_amounts = $past_thirty_days + $order_value_irt;
         // Rules about user trade amount
         switch (true) {
             case ($sum_of_amounts >= 0 && $sum_of_amounts <= 100000000):
        return 0.0025;
             case ($sum_of_amounts >= 100000000 && $sum_of_amounts <= 500000000):
                 return 0.0022;
             case ($sum_of_amounts >= 500000000 && $sum_of_amounts <= 1000000000):
                 return 0.002;
             case ($sum_of_amounts >= 1000000000 && $sum_of_amounts <= 3000000000):
                 return 0.00175;
             case ($sum_of_amounts > 3000000000):
                 return 0.0015;
         }
    }

    private function calculateStaticPercentForTether()
    {

         $past_thirty_days = MongoTradeAmount::pastThirtyDays($this->user->id);

         // Rules about user trade amount
         switch (true) {
             case ($past_thirty_days >= 0 && $past_thirty_days <= 100000000):
        return 0.0025;
             case ($past_thirty_days > 100000000 && $past_thirty_days <= 500000000):
                 return 0.0022;
             case ($past_thirty_days > 500000000 && $past_thirty_days <= 1000000000):
                 return 0.002;
             case ($past_thirty_days > 1000000000 && $past_thirty_days <= 3000000000):
                 return 0.00175;
             case ($past_thirty_days > 3000000000):
                 return 0.0015;
         }
    }

    private function definitionOfBasicProperties($required_data)
    {
        $this->request = $required_data['request'];
        $this->user = $required_data['user'];
        $this->market = ($this->request->pair_type == 'usdt' || $this->request->pair_type ==  'USDT') ? 'USDT' : strtoupper($this->request->pair_type) . 'USDT'; //$this->request->pair_type . 'USDT';
        $this->wage_percent = $required_data['static_percent'];
        $this->order_market_type = $required_data['order_market_type'];
        $this->tether_price = $required_data['tether_price'];
        $this->network_percent = $required_data['network_wage_percent'];
        $this->reserve_payment = @$this->request->reserve_payment ?: false;
    }

    private function definitionOfPropertiesForLog($type, $role, $trade_type)
    {
        $this->type_log = $type;
        $this->role_log = $role;
        $this->trade_type = $trade_type;
    }

    private function calculatePairPrice()
    {
        $data_provided = [
            'amount_type' => $this->request->amount_type,
            'pair_price' => $this->request->pair_price,
            'tether_price' => $this->tether_price
        ];
        switch ($data_provided['amount_type']) {
            case 'usdt':
                return $data_provided['pair_price'];
            case 'toman':
            default:
                return $data_provided['pair_price'] / $data_provided['tether_price'];
        }
    }


    private function sendSimpleNotification($role)
    {

        $message = 'update_quick_order' . ':' . $this->order_info->id;

        switch ($role) {

            case OrdersList::BUY_ROLE:

                $title = 'update_buy_order_title' . ':' . $this->request->pair_type
                    . ':' . $this->request->amount;

                if (str_contains($message, ':')) {

                    $temp_message = explode(':', $message);
                    $final_message = str_replace('[ID]', $temp_message[1], __($temp_message[0]));
                } else {

                    $final_message = $message;
                }

                if (str_contains($title, ':')) {

                    $temp_title = explode(':', $title);
                    $final_title = str_replace('[ID]', $temp_title[1], __($temp_title[0]));
                } else {

                    $final_title = $title;
                }

                $this->sendSuccessfulNotification(__($final_title), __($final_message));

                // Notification::send(User::find($this->user->id), new UserMessagesNotification(User::find($this->user->id), $message, $title));

                UserMessageNotificationJob::dispatch(User::find($this->user->id), $message, $title);

                break;

            case OrdersList::SELL_ROLE:

                $title = 'update_sell_order_title' . ':' . $this->request->pair_type . ':' . $this->request->amount;

                if (str_contains($message, ':')) {

                    $temp_message = explode(':', $message);
                    $final_message = str_replace('[ID]', $temp_message[1], __($temp_message[0]));
                } else {

                    $final_message = $message;
                }

                if (str_contains($title, ':')) {

                    $temp_title = explode(':', $title);
                    $final_title = str_replace('[ID]', $temp_title[1], __($temp_title[0]));
                } else {

                    $final_title = $title;
                }

                $this->sendSuccessfulNotification(__($final_title), __($final_message));

                // Notification::send(User::find($this->user->id), new UserMessagesNotification(User::find($this->user->id), $message, $title));
                UserMessageNotificationJob::dispatch(User::find($this->user->id), $message, $title);

                break;
        }
    }

    private function storeBitazarLimitOrders()
    {
        $bitazar_order_info = [
            'user_id' => $this->user->id,
            'order_id' => $this->order_info->id,
            'market' => strtoupper($this->request->pair_type) . 'USDT',
            'type' => 'buy',
            'amount' => number_format($this->request->pair_amount, $this->trading_decimal, '.', ''),
            'price' => number_format($this->pair_price, $this->pricing_decimal, '.', ''),
            'static_percent' => $this->static_percent,
        ];
        BitazarLimitOrders::store($bitazar_order_info);
    }

    private function reserveOnlinePayment($type_payment, $trans_id, $gateway_name) // order_irt, order_usdt, order_qty, market, type_payment, pair_value_usdt, pair_value_irt, status, gateway_name, user_id, tether_price
    {

        $payment = [
            'user_id' => $this->user->id,
            'gateway_name' => $gateway_name,
            'market' => strtoupper($this->request->pair_type),
            'type_payment' => $type_payment, // (ex: wallet or trade)
            'order_qty' => $this->order_qty,
            'order_usdt' => $this->total_value_usdt,
            'order_irt' => $this->total_value_irt,
            'pair_value_usdt' => $this->coinex_market_info->last,
            'pair_value_irt' => $this->coinex_market_info->last * $this->tether_price,
            'tether_price' => $this->tether_price,
            'total_price' => $this->total_value_usdt,
            'trans_id' => $trans_id,
            'tracking_id' => UniqueCode::generate('payment_history', 'tracking_id', 5)
        ];
        return PaymentHistory::store($payment);
    }

    private function generatePaymentUrl($amount, $driver = 'nextpay')
    {
        return PaymentHelper::shetabitPay($driver, $amount, $this->user->mobile, ['user_id' => $this->user->id], '/trade/verify/');
    }

    private function detectTradeType($type, $role)
    {
        switch ($type) {

            case OrdersList::MARKET_TYPE:

                if (@$this->currency_setting->type == 1 || !$this->currency_setting || (@$this->currency_setting->type == 2 && (@$this->currency_setting->loss > $this->coinex_market_info->last))) {

                    return OrdersList::REAL;
                } elseif (@$this->currency_setting->type == 2) {

                    return OrdersList::VIRTUAL;
                }

                break;
            case OrdersList::LIMIT_TYPE:

                if (@$this->currency_setting->type == 1 || !$this->currency_setting) {

                    return OrdersList::REAL;
                } elseif (@$this->currency_setting->type == 2) {

                    return OrdersList::VIRTUAL;
                }

                break;
        }
    }

    private function defineMarketName($payment_type)
    {
        switch ($payment_type) {
            default:
            case 'USDT':
                return $this->market;
            case 'IRR':
                return str_replace('USDT', 'IRT', $this->market);
        }
    }

    private function definePriceType($search_result, $role)
    {
        switch ($role) {

            case OrdersList::BUY_ROLE:
                switch ($search_result->payment_type) {
                    case 'IRR':
                        $this->order_price_type = OrdersList::IRT;
                        $this->total_price_type = OrdersList::IRT;
                        break;
                    case 'USDT':
                        $this->order_price_type = OrdersList::USDT;
                        $this->total_price_type = OrdersList::USDT;
                        break;
                }
                break;


            case OrdersList::SELL_ROLE:
                switch ($search_result->received_type) {
                    case 'IRR':
                        $this->order_price_type = OrdersList::IRT;
                        $this->total_price_type = OrdersList::IRT;
                        break;
                    case 'USDT':
                        $this->order_price_type = OrdersList::USDT;
                        $this->total_price_type = OrdersList::USDT;
                        break;
                }
                break;
        }
    }

    private function defineOrderPrice($search_result, $role)
    {

        switch ($role) {

            case OrdersList::BUY_ROLE:
                switch ($search_result->payment_type) {
                    case 'IRR':
                        $this->order_price = $search_result->irt_price;
                        $this->total_price = $search_result->total_irt_price;
                        break;
                    case 'USDT':
                        $this->order_price = $search_result->usdt_price;
                        $this->total_price = $search_result->total_usdt_price;
                        break;
                }
                break;


            case OrdersList::SELL_ROLE:
                switch ($search_result->received_type) {
                    case 'IRR':
                        $this->order_price = $search_result->irt_price;
                        $this->total_price = $search_result->total_irt_price;
                        break;
                    case 'USDT':
                        $this->order_price = $search_result->usdt_price;
                        $this->total_price = $search_result->total_usdt_price;
                        break;
                }
                break;
        }
    }

    private function calculateFees($order_value, $order_qty, $market_or_limit, $wage_percent, $network_percent, $payment_type, $buy_or_sell, $market) // order_value_usdt , order_value_irt, order_qty, order_wage_fee, order_network_wage_fee, order_total_fee, total_value_usdt, total_value_irt, $order_wage_fee_irt, $order_network_wage_fee_irt, $order_total_fee_irt
    {

        // Remove commas from $order_value
        $order_value_usdt = str_replace(',', '', $order_value);

        if ($market_or_limit == OrdersList::MARKET_TYPE && ($payment_type == 'IRT' || strtolower($payment_type) == 'toman') &&  $buy_or_sell == OrdersList::BUY_ROLE) {
            $order_value_irt = $order_value_usdt * $this->tether_price;
        }
        if ($buy_or_sell == OrdersList::BUY_ROLE && $market !== 'USDT') {
            $order_value_irt = ceil($order_value_usdt * $this->tether_price);
        } else {
            $order_value_irt = $order_value_usdt * $this->tether_price;
        }
        $order_value_irt = number_format($order_value_irt, 0, '.', '');

        // Order wage by usdt
        $order_wage_fee_usdt = $this->roundUpToDecimal($wage_percent * $order_value_usdt, 6);
        $order_network_wage_fee_usdt = $this->roundUpToDecimal($network_percent * $order_value_usdt, 6);
        $order_total_fee_usdt = $order_wage_fee_usdt + $order_network_wage_fee_usdt;

        // Order wage by irt
        $order_wage_fee_irt = ceil($order_wage_fee_usdt * $this->tether_price); //ceil
        $order_network_wage_fee_irt = ceil($order_network_wage_fee_usdt * $this->tether_price); //ceil
        $order_total_fee_irt = $order_wage_fee_irt + $order_network_wage_fee_irt;

        //order total value (order value + wage fee + network wage fee)
        $total_value_usdt = $order_value_usdt; // + $order_total_fee_usdt;
        $total_value_irt = $order_value_irt; // + $order_total_fee_irt;

        //we deduct this site wage fee from total value here to prepare it for coinex order (for example 10 USDT worth of dogecoin order becomes 9.8 USDT order , 0.2 is this site profit )
        //coinex will deduct its fee from it too and then execute it
        //for example 10 USDT worth of dogecoin will finally get 9.6 USDT worth of it - 0.2 is for this site and 0.2 for coinex
        $order_value_coinex_usdt = $order_value_usdt - $order_wage_fee_usdt;
        $order_value_coinex_irt = $order_value_irt - $order_wage_fee_irt;

        //this is used for sell order (sell market and sell limit) to send to coinex
        //we deduct this site wage from amound user is selling and then send to coinex
        // coinex will deduct its own wage too and then process it
        $order_wage_qty = $wage_percent * $order_qty;
        $order_network_wage_qty = $network_percent * $order_qty;
        $order_qty_coinex = $order_qty - $order_wage_qty;

        $max_qty_user = $order_qty_coinex - $order_network_wage_qty;
        $max_usdt_user = $order_value_coinex_usdt - $order_network_wage_fee_usdt;
        // $max_usdt_user = $order_value_usdt - $order_total_fee_usdt;
        // $max_irt_user = $order_value_irt - $order_total_fee_irt;
        $max_irt_user = $order_value_coinex_irt - $order_network_wage_fee_irt;
        $filled_qty = 0;
        $filled_usdt = 0;
        $filled_irt = 0;

        if ($market_or_limit == OrdersList::MARKET_TYPE) {
            $filled_qty = $max_qty_user;
            $filled_usdt = $max_usdt_user;
            $filled_irt = $max_irt_user;
        }

        return compact(
            'order_value_usdt',
            'order_value_irt',
            'order_wage_fee_usdt',
            'order_network_wage_fee_usdt',
            'order_total_fee_usdt',
            'total_value_usdt',
            'total_value_irt',
            'order_wage_fee_irt',
            'order_network_wage_fee_irt',
            'order_total_fee_irt',
            'order_value_coinex_usdt',
            'order_value_coinex_irt',
            'order_wage_qty',
            'order_qty_coinex',
            'order_network_wage_qty',
            'max_qty_user',
            'max_usdt_user',
            'max_irt_user',
            'filled_qty',
            'filled_usdt',
            'filled_irt'
        );
    }

    public static function roundUpToDecimal($number, $decimalPlaces = 2)
    {
        $multiplier = pow(10, $decimalPlaces);
        return ceil($number * $multiplier) / $multiplier;
    }

    private function isUsdt(): bool
    {
        if ((strtolower($this->request->pair_type) === 'usdt') || (strtolower($this->request->pair_type) === 'USDT'))
            return true;
        else
            return false;
    }


    private function prepareDetectTypeVariables() // order_type, order_role
    {

        $order_type = null;
        $order_role = null;

        switch (true) {

            case str_contains($this->order_market_type, 'market'):
                $order_type = OrdersList::MARKET_TYPE;
                break;

            case str_contains($this->order_market_type, 'limit'):
                $order_type = OrdersList::LIMIT_TYPE;
                break;
        }


        switch (true) {

            case str_contains($this->order_market_type, '_buy'):
                $order_role = OrdersList::BUY_ROLE;
                break;

            case str_contains($this->order_market_type, '_sell'):
                $order_role = OrdersList::SELL_ROLE;
                break;
        }

        return compact('order_type', 'order_role');
    }

    private function revertSendCoinexMarketOrder($role) // This method is reverse (ex: role = 1 send market sell or role = 2 send market buy)
    {
        switch ($role) {

            case OrdersList::BUY_ROLE: // Buy order

                $coinex_order_info = [
                    'access_id' => $this->coinex_access_id,
                    'market' => strtoupper($this->market),
                    'type' => 'sell',
                    'amount' => number_format($this->order_qty_coinex, $this->trading_decimal, '.', ''),
                    'tonce' => now()->toDateTimeString(),
                    'account_id' => 0,
                    'client_id' => @$this->user->mobile
                ];
                $this->coinex_order_info = $coinex_order_info;

                $this->coinex_order_result = SellExchangeTrait::SellOrderCoinex($coinex_order_info, 'market');

                break;

            case OrdersList::SELL_ROLE: // Sell order

                $coinex_order_info = [
                    'access_id' => $this->coinex_access_id,
                    'market' => strtoupper($this->market),
                    'type' => 'buy',
                    'amount' => number_format($this->order_value_coinex_usdt, $this->pricing_decimal, '.', ''),
                    'tonce' => now()->toDateTimeString(),
                    'account_id' => 0,
                    'client_id' => @$this->user->mobile
                ];
                $this->coinex_order_info = $coinex_order_info;

                $this->coinex_order_result = BuyExchangeTrait::coinexBuyOrder($coinex_order_info, 'market');

                break;
        }
    }

    private function isInvoice()
    {
        if ($this->request->request_type == 'invoice')
            return true;
        else
            return false;
    }

    private function isBuy()
    {
        if ($this->request->request_type == 'buy')
            return true;
        else
            return false;
    }

    private function handleRollbackException($exception, $controller_name, $method_name)
    {
        DB::rollBack();

        $error_data = [
            'user_id' => @$this->user->id,
            'pair_type' => @$this->request->pair_type,
            'pair_amount' => @$this->request->pair_amount,
            'type' => @$this->type_log,
            'role' => @$this->role_log,
            'amount_type' => @$this->request->amount_type,
            'trade_type' => @$this->trade_type
        ];

        $error = $exception->getMessage() . ' in ' .  $exception->getFile() . ' at ' . $exception->getLine() . ' line.';

        $log = [
            'controller' => $controller_name,
            'method' => $method_name,
            'data' => json_encode($error_data),
            'error' => $error
        ];

        Logs::store($log);

        return Response::failed($error, null, 500, -1);
    }

    private function checkIsDone(): bool
    {
        if ($this->coinex_order_result->status == 'done')
            return true;
        else
            return false;
    }

    /**
     * Used for when limit order is completed immediately
     */
    private function calculateLimitOrderDoneProperties($order_info, $done_order_info): array
    {

        // load order info
        $temp_done_order = $done_order_info;
        $temp_order = $order_info;

        $last_deal_amount = $temp_order->max_qty_user;
        $last_deal_price = $temp_order->currency_price;
        $last_deal_value_usdt = $last_deal_amount * $temp_order->currency_price;
        $last_deal_value_irt =  $last_deal_value_usdt * $temp_order->tether_price;
        $wallet_name = str_replace('USDT', '', $done_order_info->market);


        $real_filled_qty = OrderRepository::increaseFilledQty($temp_order->id, $last_deal_amount);
        $real_filled_usdt = OrderRepository::increaseFilledUsdt($temp_order->id, $last_deal_value_usdt);
        $real_filled_irt = OrderRepository::increaseFilledIrt($temp_order->id, $last_deal_value_irt);
        $max_qty_user = $temp_order->max_qty_user;
        $irr_or_usdt = $temp_order->payment_type; //usdt or irt

        $buy_or_sell = $temp_order->role;
        if ($buy_or_sell == OrdersList::BUY_ROLE)
            $irr_or_usdt = $temp_order->payment_type;
        else
            $irr_or_usdt = $temp_order->received_type;

        return [
            $temp_done_order,
            $temp_order,
            $last_deal_amount,
            $last_deal_price,
            $last_deal_value_usdt,
            $last_deal_value_irt,
            $wallet_name,
            $real_filled_qty,
            $real_filled_usdt,
            $real_filled_irt,
            $max_qty_user,
            $irr_or_usdt,
            $buy_or_sell
        ];
    }

    private function handleSuccessfulLimitOrderDone($order_info, $done_order_info)
    {

        try {


            list($temp_done_order, $temp_order, $last_deal_amount, $last_deal_price, $last_deal_value_usdt, $last_deal_value_irt, $wallet_name, $real_filled_qty, $real_filled_usdt, $real_filled_irt, $max_qty_user, $irr_or_usdt, $buy_or_sell) =
                $this->calculateLimitOrderDoneProperties($order_info, $done_order_info);

            $order_id = $order_info->id;

            switch ($temp_order->role) {

                case OrdersList::BUY_ROLE: /* buy */
                    $title = 'update_buy_order_title';

                    UserWalletsRepository::handleWalletUpdatOnLimitUpdate($this->user->id, $buy_or_sell, $irr_or_usdt, $wallet_name, $real_filled_qty, $real_filled_irt, $real_filled_usdt, $temp_order->id, true);
                    $this->userWalletsRepository->freeRemainingFreezeOfOrder($temp_order->id, true);

                    break;

                case OrdersList::SELL_ROLE: /* sell */
                    $title = 'update_sell_order_title';

                    UserWalletsRepository::handleWalletUpdatOnLimitUpdate($this->user->id, $buy_or_sell, $irr_or_usdt, $wallet_name, $real_filled_qty, $real_filled_irt, $real_filled_usdt, $temp_order->id, true);
                    $this->userWalletsRepository->freeRemainingFreezeOfOrder($temp_order->id, true);

                    break;
            }

            // Send notification to user

            if ($order_info->role == OrdersList::BUY_ROLE) {

                unset($message, $title);
                $message = 'update_order_completed_content' . ':' . $order_id;
                $title = 'update_buy_order_title' . ':' . $order_info->market . ':' . $order_info->amount;
                // Notification::send($this->user, new UserMessagesNotification($this->user, $message, $title));
                UserMessageNotificationJob::dispatch($this->user, $message, $title);

                $message = $this->notificationRepository::prepareNotificationParameter($message);
                $title = $this->notificationRepository::prepareNotificationParameterTitle($title);
                $this->sendSuccessfulNotification($title, $message);
            } elseif ($order_info->role == OrdersList::SELL_ROLE) {

                unset($message, $title);
                $message = 'update_order_completed_content' . ':' . $order_id;
                $title = 'update_sell_order_title' . ':' . $order_info->market . ':' . $order_info->amount;
                // Notification::send($this->user, new UserMessagesNotification($this->user, $message, $title));
                UserMessageNotificationJob::dispatch($this->user, $message, $title);

                $message = $this->notificationRepository::prepareNotificationParameter($message);
                $title = $this->notificationRepository::prepareNotificationParameterTitle($title);
                $this->sendSuccessfulNotification($title, $message);
            }

            // SmsHelper::sendMessage($this->user->mobile, $this->templates_id['limit_order_complete'], [$done_order_info->market, $order_id]);
            SmsJob::dispatch($this->user->mobile, $this->templates_id['limit_order_complete'], [$done_order_info->market, $order_id]);

            // Call event to give commissions
            $exchange_symbol = $wallet_name;
            $currency = ExchangeList::findBySymbol($exchange_symbol);
            event(new pay_commision($this->user, $order_info->current_wage * $order_info->tether_price, $currency->id));

            //set wage of this order is deducted
            if ($temp_order->is_wage_reducted == 0)
                OrderRepository::setWageReducted($temp_order->user_id);

            //set amount perentage to 100
            $percentage_done = 100;
            // Update order list to Done
            $order_info = OrdersList::updateLimitOrders($order_id, OrdersList::DONE_STATUS, $percentage_done);

            // Notification with api
            $this->sendSuccessfulOrderNotification();
        } catch (\Exception $exception) {

            $error_data = [
                'user_id' => @$this->user->id,
                'pair_type' => @$this->request->pair_type,
                'pair_amount' => @$this->request->pair_amount,
                'type' => @$this->type_log,
                'role' => @$this->role_log,
                'amount_type' => @$this->request->amount_type,
                'trade_type' => @$this->trade_type
            ];

            $error = $exception->getMessage() . ' in ' .  $exception->getFile() . ' at ' . $exception->getLine() . ' line.';

            $log = [
                'controller' => 'coinex2',
                'method' => 'handleSuccessfulLimitOrderDone',
                'data' => json_encode($error_data),
                'error' => $error
            ];

            Logs::store($log);
        }
    }

    private function prepareNotificationParameter($message)
    {
        if (str_contains($message, ':')) {

            $temp_message = explode(':', $message);
            return str_replace('[ID]', $temp_message[1], __($temp_message[0]));
        } else {

            return $message;
        }
    }
}
