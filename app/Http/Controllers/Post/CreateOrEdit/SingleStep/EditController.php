<?php
/**
 * LaraClassified - Classified Ads Web Application
 * Copyright (c) BedigitCom. All Rights Reserved
 *
 * Website: https://bedigit.com
 *
 * LICENSE
 * -------
 * This software is furnished under a license and may be used and copied
 * only in accordance with the terms of such license and with the inclusion
 * of the above copyright notice. If you Purchased from CodeCanyon,
 * Please read the full License from here - http://codecanyon.net/licenses/standard
 */

namespace App\Http\Controllers\Post\CreateOrEdit\SingleStep;

// Increase the server resources
$iniConfigFile = __DIR__ . '/../../../../../Helpers/Functions/ini.php';
if (file_exists($iniConfigFile)) {
	$configForUpload = true;
	include_once $iniConfigFile;
}

use App\Helpers\Ip;
use App\Helpers\Payment as PaymentHelper;
use App\Helpers\UrlGen;
use App\Http\Controllers\Auth\Traits\VerificationTrait;
use App\Http\Controllers\Post\CreateOrEdit\Traits\RetrievePaymentTrait;
use App\Http\Controllers\Post\Traits\CustomFieldTrait;
use App\Http\Controllers\Post\CreateOrEdit\Traits\MakePaymentTrait;
use App\Http\Requests\PostRequest;
use App\Models\City;
use App\Models\Picture;
use App\Models\Post;
use App\Models\PostType;
use App\Models\Category;
use App\Models\Package;
use App\Http\Controllers\FrontController;
use App\Models\Scopes\ReviewedScope;
use App\Models\Scopes\StrictActiveScope;
use App\Models\Scopes\VerifiedScope;
use Torann\LaravelMetaTags\Facades\MetaTag;

class EditController extends FrontController
{
	use VerificationTrait, CustomFieldTrait, MakePaymentTrait, RetrievePaymentTrait;
	
	public $request;
	public $data;
	public $msg = [];
	public $uri = [];
	public $packages;
	public $paymentMethods;
	
	/**
	 * EditController constructor.
	 */
	public function __construct()
	{
		parent::__construct();
		
		// From Laravel 5.3.4 or above
		$this->middleware(function ($request, $next) {
			$this->commonQueries();
			
			return $next($request);
		});
	}
	
	/**
	 * Common Queries
	 */
	public function commonQueries()
	{
		// References
		$data = [];
		
		// Messages
		$this->msg['post']['success'] = t("your_ad_has_been_updated");
		$this->msg['checkout']['success'] = t("We have received your payment");
		$this->msg['checkout']['cancel'] = t("payment_cancelled_text");
		$this->msg['checkout']['error'] = t("payment_error_text");
		
		// Set URLs
		$this->uri['previousUrl'] = 'edit/#entryId';
		$this->uri['nextUrl'] = str_replace(['{slug}', '{id}'], ['#entrySlug', '#entryId'], (config('routes.post') ?? '#entrySlug/#entryId'));
		$this->uri['paymentCancelUrl'] = url('edit/#entryId/payment/cancel');
		$this->uri['paymentReturnUrl'] = url('edit/#entryId/payment/success');
		
		// Payment Helper init.
		PaymentHelper::$country = collect(config('country'));
		PaymentHelper::$lang = collect(config('lang'));
		PaymentHelper::$msg = $this->msg;
		PaymentHelper::$uri = $this->uri;
		
		// Get Packages
		$this->packages = Package::applyCurrency()->with('currency')->orderBy('lft')->get();
		$data['countPackages'] = $this->packages->count();
		view()->share('packages', $this->packages);
		view()->share('countPackages', $this->packages->count());
		
		PostRequest::$packages = $this->packages;
		PostRequest::$paymentMethods = $this->paymentMethods;
		
		// Get Categories
		$data['categories'] = Category::where(function ($query) {
			$query->where('parent_id', 0)->orWhereNull('parent_id');
		})->with(['children'])->orderBy('lft')->get();
		view()->share('categories', $data['categories']);
		
		if (config('settings.single.show_post_types')) {
			// Get Post Types
			$data['postTypes'] = PostType::orderBy('lft')->get();
			view()->share('postTypes', $data['postTypes']);
		}
		
		// Save common's data
		$this->data = $data;
	}
	
	/**
	 * Show the form the create a new ad post.
	 *
	 * @param $postId
	 * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\View\View
	 */
	public function getForm($postId)
	{
		// Check if the form type is 'Multi Steps Form', and make redirection to it (permanently).
		if (config('settings.single.publication_form_type') == '1') {
			return redirect(url('posts/' . $postId . '/edit'), 301)->header('Cache-Control', 'no-store, no-cache, must-revalidate');
		}
		
		$data = [];
		
		$post = Post::currentCountry()->with([
			'city', 'latestPayment' => function ($builder) {
				$builder->with(['package'])->withoutGlobalScope(StrictActiveScope::class);
			},
		])->withoutGlobalScopes([VerifiedScope::class, ReviewedScope::class])
			->where('user_id', auth()->user()->id)
			->where('id', $postId)
			->first();
		
		if (empty($post)) {
			abort(404);
		}
		
		view()->share('post', $post);
		
		// Share the Post's Latest Payment Info (If exists)
		$this->sharePostLatestPaymentInfo($post);
		
		// Get the Post's Administrative Division
		if (config('country.admin_field_active') == 1 && in_array(config('country.admin_type'), ['1', '2'])) {
			if (!empty($post->city)) {
				$adminType = config('country.admin_type');
				$adminModel = '\App\Models\SubAdmin' . $adminType;
				
				// Get the City's Administrative Division
				$admin = $adminModel::where('code', $post->city->{'subadmin' . $adminType . '_code'})->first();
				if (!empty($admin)) {
					view()->share('admin', $admin);
				}
			}
		}
		
		// Meta Tags
		MetaTag::set('title', t('update_my_ad'));
		MetaTag::set('description', t('update_my_ad'));
		
		return appView('post.createOrEdit.singleStep.edit', $data);
	}
	
	/**
	 * Update ad post.
	 *
	 * @param $postId
	 * @param PostRequest $request
	 * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
	 */
	public function postForm($postId, PostRequest $request)
	{
		$post = Post::currentCountry()->with(['latestPayment'])
			->withoutGlobalScopes([VerifiedScope::class, ReviewedScope::class])
			->where('user_id', auth()->user()->id)
			->where('id', $postId)
			->first();
		
		if (empty($post)) {
			abort(404);
		}
		
		// Get the Post's City
		$city = City::find($request->input('city_id', 0));
		if (empty($city)) {
			flash(t("posting_ads_is_disabled"))->error();
			
			return back()->withInput();
		}
		
		// Conditions to Verify User's Email or Phone
		$emailVerificationRequired = config('settings.mail.email_verification') == 1
			&& $request->filled('email')
			&& $request->input('email') != $post->email;
		$phoneVerificationRequired = config('settings.sms.phone_verification') == 1
			&& $request->filled('phone')
			&& $request->input('phone') != $post->phone;
		
		/*
		 * Allow admin users to approve the changes,
		 * If the ads approbation option is enable,
		 * And if important data have been changed.
		 */
		if (config('settings.single.posts_review_activation')) {
			if (
				md5($post->title) != md5($request->input('title')) ||
				md5($post->description) != md5($request->input('description'))
			) {
				$post->reviewed = 0;
			}
		}
		
		// Update Post
		$input = $request->only($post->getFillable());
		foreach ($input as $key => $value) {
			$post->{$key} = $value;
		}
		$post->negotiable = $request->input('negotiable');
		$post->phone_hidden = $request->input('phone_hidden');
		$post->lat = $city->latitude;
		$post->lon = $city->longitude;
		$post->ip_addr = Ip::get();
		
		// Email verification key generation
		if ($emailVerificationRequired) {
			$post->email_token = md5(microtime() . mt_rand());
			$post->verified_email = 0;
		}
		
		// Phone verification key generation
		if ($phoneVerificationRequired) {
			$post->phone_token = mt_rand(100000, 999999);
			$post->verified_phone = 0;
		}
		
		// Save
		$post->save();
		
		// Save all pictures
		$files = $request->file('pictures');
		if (!empty($files)) {
			$i = 0;
			foreach ($files as $key => $file) {
				if (empty($file)) {
					continue;
				}
				
				// Delete old file if new file has uploaded
				// Check if current Post have a pictures
				$picturePosition = $i;
				$picture = Picture::where('post_id', $post->id)->where('id', $key)->first();
				if (!empty($picture)) {
					$picturePosition = $picture->position;
					$picture->delete();
				}
				
				// Save Post's Picture in DB
				$picture = new Picture([
					'post_id'  => $post->id,
					'filename' => $file,
					'position' => $picturePosition,
				]);
				if (isset($picture->filename) && !empty($picture->filename)) {
					$picture->save();
				}
				
				$i++;
			}
		}
		
		// Custom Fields
		$this->createPostFieldsValues($post, $request);
		
		
		// MAKE A PAYMENT (IF NEEDED)
		
		// Check if the selected Package has been already paid for this Post
		$alreadyPaidPackage = false;
		if (!empty($post->latestPayment)) {
			if ($post->latestPayment->package_id == $request->input('package_id')) {
				$alreadyPaidPackage = true;
			}
		}
		
		// Check if Payment is required
		$package = Package::find($request->input('package_id'));
		if (!empty($package)) {
			if ($package->price > 0 && $request->filled('payment_method_id') && !$alreadyPaidPackage) {
				// Send the Payment
				return $this->sendPayment($request, $post);
			}
		}
		
		// IF NO PAYMENT IS MADE (CONTINUE)
		
		
		// Get Next URL
		flash(t('your_ad_has_been_updated'))->success();
		$nextStepUrl = UrlGen::postUri($post);
		
		// Send Email Verification message
		if ($emailVerificationRequired) {
			$this->sendVerificationEmail($post);
			$this->showReSendVerificationEmailLink($post, 'post');
		}
		
		// Send Phone Verification message
		if ($phoneVerificationRequired) {
			// Save the Next URL before verification
			session()->put('itemNextUrl', $nextStepUrl);
			
			$this->sendVerificationSms($post);
			$this->showReSendVerificationSmsLink($post, 'post');
			
			// Go to Phone Number verification
			$nextStepUrl = 'verify/post/phone/';
		}
		
		// Redirection
		return redirect($nextStepUrl);
	}
}
