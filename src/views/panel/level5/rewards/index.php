<?php
use App\Services\LoginService;
use App\Services\LoyaltyRewardsService;
use App\Utils\Router;
use App\Utils\TemplateResponse;
$router=new Router();$router->get(function(){ $user=LoginService::getSession();$service=new LoyaltyRewardsService();return TemplateResponse::render(__DIR__.'/index.twig',['balance'=>$service->balance((int)$user->getOwner(),(int)$user->getId()),'history'=>$service->history((int)$user->getOwner(),(int)$user->getId()),'settings'=>$service->settings((int)$user->getOwner())]);});$router->run();
