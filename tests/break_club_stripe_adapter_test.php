<?php
declare(strict_types=1);

use App\Services\BreakClubCheckout;
use App\Services\ConfigService;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Services/BreakClubCheckout.php';

final class ClubCheckoutRequestRecorder implements ClientInterface
{
    public array $calls = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1')
    {
        $this->calls[] = [$method,$absUrl,$headers,$params];
        return [json_encode([
            'id'=>'cs_test_event',
            'object'=>'checkout.session',
            'mode'=>'payment',
            'status'=>'open',
            'url'=>'https://checkout.stripe.test/session',
            'payment_intent'=>[
                'id'=>'pi_event','object'=>'payment_intent',
                'latest_charge'=>['id'=>'ch_event','object'=>'charge','amount_captured'=>6700, 'amount_refunded'=>0],
            ],
        ], JSON_THROW_ON_ERROR),200,[]];
    }
}

$http = new ClubCheckoutRequestRecorder();
ApiRequestor::setHttpClient($http);
ConfigService::$STRIPE_KEY = 'sk_test_offline_fixture';
$_ENV['SITE_PUBLIC_BASE_URL'] = 'https://vnv.example.test';
$gateway = new BreakClubCheckout();
$registration = ['owner_id'=>2,'id'=>118,'event_id'=>605,'email'=>'qa@example.test','party_size'=>2,
    'unit_cents'=>3350,'event_title'=>'Coffee at nine'];
$session = $gateway->create($registration,'acct_fixture',2);
$expanded = $gateway->session($session->id,'acct_fixture');
[$method,$url,$headers,$payload] = $http->calls[0];
[$get,$fetchUrl,$fetchHeaders,$query] = $http->calls[1];
$assert = static function(bool $value,string $message): void {
    if (!$value) {throw new RuntimeException($message);}
    echo "PASS $message\n";
};
$assert($method==='post' && str_ends_with($url,'/v1/checkout/sessions') && $payload['mode']==='payment','Stripe SDK prepares a one-time hosted Checkout session');
$assert(in_array('Stripe-Account: acct_fixture',$headers,true) && in_array('Idempotency-Key: break-club-2-118-2',$headers,true),
    'merchant destination and stable per-attempt idempotency key');
$line=$payload['line_items'][0];
$assert((int)$line['quantity']===2 && (int)$line['price_data']['unit_amount']===3350 && $line['price_data']['currency']==='usd',
    'checkout amount comes from the invitation snapshot');
$assert($payload['metadata']===$payload['payment_intent_data']['metadata'] && $payload['metadata']['break_registration_id']==='118'
    && $payload['success_url']==='https://vnv.example.test/api/public/break-club/receipt?session_id={CHECKOUT_SESSION_ID}',
    'verified receipt route and registration attribution on both Stripe objects');
$assert($get==='get' && str_ends_with($fetchUrl,'/v1/checkout/sessions/cs_test_event') && $query['expand']===['payment_intent.latest_charge']
    && $expanded->payment_intent->latest_charge->amount_captured===6700,'SDK retrieves underlying charge and refund state');
