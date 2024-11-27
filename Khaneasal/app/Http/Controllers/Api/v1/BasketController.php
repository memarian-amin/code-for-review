<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Api\v1\Discount;
use App\Models\Api\v1\Settings;
use Illuminate\Http\Request;
use App\Models\Api\v1\Basket;
use App\Helpers\MainUrlHelper;
use App\Models\Api\v1\Product;
use Elegant\Sanitizer\Sanitizer;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class BasketController extends Controller
{

    public function index(Request $request)
    {
        $baskets = Basket::get(Auth::guard('api')->id());
        $data = [];
        $factor = [];
        $data['factor']['total_price'] = 0;
        $data['factor']['payable_price'] = 0;
        $factor['total_price'] = null;
        $data['list'] = [];

        $settings = Settings::getFirst('keeping_cost');
        $keeping_cost = $settings->value;

        $discount_code = false;
        if ($request->has('discount_code'))
            $discount_code = Discount::findByCode($request->discount_code);


        foreach ($baskets as $basket) {

            $factor['total_price'][] = (($basket->price + $basket->product->cost_keep_honey) - $basket->product->toman_discount) * $basket->count;
            $factor['payable_price'][] = ($basket->product->total_price_discount + $basket->product->cost_keep_honey) * $basket->count;
            $data ['list'][] = [
                'basket_id' => $basket->id,
                'product_id' => $basket->product_id,
                'name' => $basket->product->name,
                'count' => $basket->count ?: 0,
                'price' => $basket->price + $basket->product->cost_keep_honey ?: 0,
                'toman_discount' => $basket->product->toman_discount ?: 0,
                'total_price_discount' => $basket->product->total_price_discount ? $basket->product->total_price_discount + $keeping_cost : 0,
                'image_url' => MainUrlHelper::getBaseUrlAdmin($request) . '/' . $basket->product ? $basket->product->image[0]->path : null,
            ];

        }

        if ($factor['total_price']) {
            for ($i = 0; $i < count($factor['total_price']); $i++) {
                $data['factor']['total_price'] += $factor['total_price'][$i];
                $data['factor']['payable_price'] += $factor['payable_price'][$i];
            }
        } else {
            $data['factor']['total_price'] = 0;
            $data['factor']['payable_price'] = 0;
        }

        if ($factor['total_price']) {

                if ($discount_code) {
                    switch ($discount_code->type) {
                        case Discount::TOMAN:
                            $data['factor']['payable_price'] =  $data['factor']['payable_price'] - $discount_code->discount_amount;
                            $data['general_discount']['amount'] = $discount_code->discount_amount;
                            $data['general_discount']['type'] = 'تومن';
                            $data['general_discount']['to_toman'] = '';
                            break;
                        case Discount::PERCENT:
                            $data['factor']['payable_price'] =  $data['factor']['payable_price'] - (($discount_code->discount_percent * $data['factor']['payable_price']) / 100);
                            $data['general_discount']['amount'] = $discount_code->discount_percent;
                            $data['general_discount']['type'] = 'درصد';
                            $data['general_discount']['to_toman'] = (($discount_code->discount_percent * $data['factor']['payable_price']) / 100);
                            break;
                    }
                }

        }

        return Response::success(__('basket_success_index'), $data, 1);

    }

    public function increase(Request $request)
    {
        $santizier = new Sanitizer($request->all(), [
            'basket_id' => 'digit',
            'product_id' => 'digit',
        ]);
        $request_sanitized = $santizier->sanitize();

        $validator = Validator::make($request_sanitized, [
            'basket_id' => ['numeric'],
            'product_id' => ['numeric']
        ]);

        if ($validator->fails()) {
            return Response::failed($validator->errors()->toArray(), null, -1);
        }

        if ($request->has('product_id')) {

            $product = Product::find($request->product_id);

            // Check count of product when user send request
            if ($product->count != Product::INFINITY)
                if ($product->count == 0)
                    return Response::failed(__('basket_failed_product_not_exist'), null, -2);

            if ($product) {

                $old_product = Basket::findProduct($product->id);

                if ($old_product) {

                    if (str_contains($old_product->product_id, ','))
                        return Response::failed(__('basket_failed_cannot_be_added'), null, -1);

                    Basket::increase($old_product->id);

                } else
                    Basket::store(Auth::guard('api')->id(), $product->id, $product->price, 1, $product->price);

            } else
                return Response::failed(__('basket_failed_product_not_found'), null, -1);

        } elseif ($request->has('basket_id'))
            Basket::increase($request->basket_id);

        $baskets = Basket::get(Auth::guard('api')->id());
        $data = [];
        $factor = [];
        $data['factor']['total_price'] = 0;
        $data['factor']['payable_price'] = 0;
        $factor['total_price'] = null;
        $data['list'] = [];

        $settings = Settings::getFirst('keeping_cost');
        $keeping_cost = $settings->value;

        foreach ($baskets as $basket) {

            if (str_contains($basket->count, ','))
                return Response::failed(__('basket_failed_cannot_be_added'), null, -1);

            $factor['total_price'][] = ($basket->price + $basket->product->cost_keep_honey) * $basket->count;
            $factor['payable_price'][] = ($basket->product->total_price_discount + $keeping_cost) * $basket->count;
            $data ['list'][] = [
                'basket_id' => $basket->id,
                'product_id' => $basket->product_id,
                'name' => $basket->product->name,
                'count' => $basket->count ?: 0,
                'price' => $basket->price ? $basket->price + $keeping_cost : 0,
                'toman_discount' => $basket->product->toman_discount ?: 0,
                'total_price_discount' => $basket->product->total_price_discount ? $basket->product->total_price_discount + $keeping_cost : 0,
                'image_url' => MainUrlHelper::getBaseUrlAdmin($request) . '/' . $basket->product ? $basket->product->image[0]->path : null,
            ];

        }

        if ($factor['total_price']) {
            for ($i = 0; $i < count($factor['total_price']); $i++) {
                $data['factor']['total_price'] += $factor['total_price'][$i];
                $data['factor']['payable_price'] += $factor['payable_price'][$i];
            }
        } else {
            $data['factor']['total_price'] = 0;
            $data['factor']['payable_price'] = 0;
        }

        return Response::success(__('basket_success_basket_has_increased'), $data, 1);

    }

    public function decrease(Request $request)
    {
        $santizier = new Sanitizer($request->all(), [
            'basket_id' => 'digit',
        ]);
        $request_sanitized = $santizier->sanitize();

        $validator = Validator::make($request_sanitized, [
            'basket_id' => ['numeric', 'required'],
        ]);

        if ($validator->fails()) {
            return Response::failed($validator->errors()->toArray(), null, -1);
        }

        Basket::decrease($request->basket_id);

        $baskets = Basket::get(Auth::guard('api')->id());
        $data = [];
        $factor = [];
        $data['factor']['total_price'] = 0;
        $data['factor']['payable_price'] = 0;
        $factor['total_price'] = null;
        $data['list'] = [];

        $settings = Settings::getFirst('keeping_cost');
        $keeping_cost = $settings->value;

        foreach ($baskets as $basket) {

            $factor['total_price'][] = ($basket->price + $basket->product->cost_keep_honey) * $basket->count;
            $factor['payable_price'][] = ($basket->product->total_price_discount + $keeping_cost) * $basket->count;
            $data ['list'][] = [
                'basket_id' => $basket->id,
                'product_id' => $basket->product_id,
                'name' => $basket->product->name,
                'count' => $basket->count ?: 0,
                'price' => $basket->price ? $basket->price + $keeping_cost : 0,
                'toman_discount' => $basket->product->toman_discount ?: 0,
                'total_price_discount' => $basket->product->total_price_discount ? $basket->product->total_price_discount + $keeping_cost : 0,
                'image_url' => MainUrlHelper::getBaseUrlAdmin($request) . '/' . $basket->product ? $basket->product->image[0]->path : null,
            ];

        }

        if ($factor['total_price']) {
            for ($i = 0; $i < count($factor['total_price']); $i++) {
                $data['factor']['total_price'] += $factor['total_price'][$i];
                $data['factor']['payable_price'] += $factor['payable_price'][$i];
            }
        } else {
            $data['factor']['total_price'] = 0;
            $data['factor']['payable_price'] = 0;
        }

        return Response::success(__('basket_success_basket_has_decreased'), $data, 1);

    }

}
