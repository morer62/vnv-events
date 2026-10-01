<?php

use App\Repositories\ClientSavedPaymentMethodsRepository;
use App\Repositories\PaymentProvidersRepository;
use App\Repositories\StoreCartItemsRepository;
use App\Repositories\StoreCartsRepository;
use App\Repositories\UserRepository;
use App\Services\Payment\PaymentProviderFactory;
use App\Services\Payment\StripeProvider;
use App\Services\Delivery\DeliveryPricingService;
use App\Services\GourmetDeliveryAreaService;
use App\Services\LoyaltyRewardsService;
use App\Services\LoginService;
use App\Utils\AvomealContext;
use App\Utils\Router;

$router = new Router();
$router->post(function () {
    header('Content-Type: application/json');
    $payload=json_decode(file_get_contents('php://input'),true);
    $sessionToken=trim((string)($payload['session_token']??''));
    $email=filter_var(trim((string)($payload['email']??'')),FILTER_VALIDATE_EMAIL);
    $name=trim((string)($payload['name']??''));
    if(!$payload||$sessionToken===''||!$email||$name===''){
        http_response_code(422); echo json_encode(['success'=>false,'message'=>'Cart, name and valid email are required.']); return '';
    }
    $shippingAddress=trim((string)($payload['shipping_address']??''));
    $ownerId=AvomealContext::ownerId(); $carts=new StoreCartsRepository(); $cart=$carts->getBySessionToken($sessionToken,$ownerId);
    if(!$cart||($cart->status??'')!==StoreCartsRepository::STATUS_ACTIVE){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Active cart not found.']);return '';}
    $address=[
        'address_1'=>trim((string)($payload['shipping_address_1']??'')),'address_2'=>trim((string)($payload['shipping_address_2']??'')),
        'city'=>trim((string)($payload['shipping_city']??'')),'state'=>trim((string)($payload['shipping_state']??'')),'zip'=>trim((string)($payload['shipping_zip']??'')),'country'=>'US'
    ];
    $items=(new StoreCartItemsRepository())->getByCart((int)$cart->id);
    $subtotal=round(array_reduce($items,fn($sum,$item)=>$sum+(float)$item->line_total,0.0),2);
    $discount=max(0.0,(float)($cart->coupon_discount??0));
    $preTaxTotal=max(0.0,round($subtotal-$discount,2));
    try{$deliveryQuote=(new DeliveryPricingService())->quoteDelivery($ownerId,'vnvevents',$address,trim((string)($payload['requested_delivery_at']??''))?:null,'CHECKOUT',null,$preTaxTotal);}catch(Throwable $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);return '';}
    $settings=(new DeliveryPricingService())->settings($ownerId,'vnvevents');
    $deliveryFee=(float)$deliveryQuote['customer_fee'];
    $tax=round(($preTaxTotal+$deliveryFee)*max(0,(float)($settings['tax_rate_percent']??7))/100,2);
    $total=round($preTaxTotal+$deliveryFee+$tax,2);
    $loyalty=['token'=>null,'points'=>0.0,'discount'=>0.0];$session=LoginService::getSession();$requested=max(0,(float)($payload['loyalty_points']??0));
    if(!empty($cart->loyalty_reservation_token)){(new LoyaltyRewardsService())->releaseReservation((string)$cart->loyalty_reservation_token);}
    if($requested>0&&$session&&(int)$session->getLevel()===5){$loyalty=(new LoyaltyRewardsService())->reserve($ownerId,(int)$session->getId(),$requested,$total,'STORE_CART',(int)$cart->id,'vnvevents');}
    $providerTotal=max(0,round($total-(float)$loyalty['discount'],2));
    if($providerTotal>0&&$providerTotal<0.50){if(!empty($loyalty['token']))(new LoyaltyRewardsService())->releaseReservation((string)$loyalty['token']);http_response_code(422);echo json_encode(['success'=>false,'message'=>'The remaining card payment must be at least $0.50. Use fewer rewards or cover the full order with rewards.']);return '';}
    $credentials=(new PaymentProvidersRepository())->getActiveProviderForOwner($ownerId);
    if(!$credentials||strtolower((string)$credentials->provider_type)!=='stripe'){http_response_code(409);echo json_encode(['success'=>false,'message'=>'Stripe is not the active checkout provider.']);return '';}
    try{
        $provider=PaymentProviderFactory::create($credentials);
        if(!$provider instanceof StripeProvider) throw new RuntimeException('Stripe provider could not be initialized.');
        $customerId=trim((string)($cart->checkout_provider_customer_id??''));
        if($customerId===''){
            $user=(new UserRepository())->getOneWithoutOwnership(['email'=>$email]);
            if($user){
                $methods=(new ClientSavedPaymentMethodsRepository())->getActiveForClient($ownerId,(int)$user->id);
                foreach($methods as $method){if(strtolower((string)$method->payment_provider)==='stripe'&&!empty($method->provider_customer_id)){$customerId=(string)$method->provider_customer_id;break;}}
            }
        }
        if($customerId==='') $customerId=(string)($provider->createCustomer((string)$email,$name,['description'=>'VNV Gourmet To Go checkout'])??'');
        if($customerId==='') throw new RuntimeException('Stripe customer could not be prepared.');
        if($providerTotal<=0.009){$carts->update(['loyalty_points_redeemed'=>$loyalty['points'],'loyalty_discount_amount'=>$loyalty['discount'],'loyalty_reservation_token'=>$loyalty['token'],'delivery_quote_id'=>(int)$deliveryQuote['quote_id'],'delivery_fee'=>$deliveryFee,'total'=>$total],['id'=>(int)$cart->id]);echo json_encode(['success'=>true,'covered_by_rewards'=>true,'amount'=>0,'order_total'=>$total,'loyalty'=>$loyalty]);return '';}
        $intent=$provider->createPaymentIntent($providerTotal,[
            'customer_id'=>$customerId,'save_for_future'=>!empty($payload['save_for_future']),
            'description'=>'VNV Gourmet To Go cart #'.(int)$cart->id,
            'idempotency_key'=>'gtg-cart-'.(int)$cart->id.'-'.(int)round($providerTotal*100),
            'metadata'=>['cart_id'=>(string)$cart->id,'site_key'=>'vnvevents','email'=>(string)$email],
        ]);
        if(!$intent) throw new RuntimeException('Stripe PaymentIntent could not be created.');
        $carts->update(['checkout_provider_customer_id'=>$customerId,'checkout_payment_intent_id'=>$intent->id,'loyalty_points_redeemed'=>$loyalty['points'],'loyalty_discount_amount'=>$loyalty['discount'],'loyalty_reservation_token'=>$loyalty['token'],'delivery_quote_id'=>(int)$deliveryQuote['quote_id'],'delivery_fee'=>$deliveryFee,'delivery_pricing_snapshot'=>json_encode($deliveryQuote,JSON_UNESCAPED_SLASHES),'guest_name'=>$name,'guest_email'=>$email,'subtotal'=>$subtotal,'discount'=>$discount,'total'=>$total,'updated_at'=>date('Y-m-d H:i:s')],['id'=>(int)$cart->id]);
        echo json_encode(['success'=>true,'client_secret'=>$intent->client_secret,'payment_intent_id'=>$intent->id,'amount'=>$providerTotal,'order_total'=>$total,'loyalty'=>$loyalty,'delivery_fee'=>$deliveryFee,'tax'=>$tax,'delivery_quote_id'=>(int)$deliveryQuote['quote_id']]);
    }catch(Throwable $e){if(!empty($loyalty['token']))(new LoyaltyRewardsService())->releaseReservation((string)$loyalty['token']);http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
    return '';
});
$router->run();
