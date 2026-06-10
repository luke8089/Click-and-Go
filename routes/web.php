<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TestimonialController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\MpesaController;
use App\Http\Controllers\Admin\PickupStationController as AdminPickupStationController;
use App\Http\Controllers\Admin\DeliveryServiceController as AdminDeliveryServiceController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\ContactMessageController as AdminContactMessageController;
use App\Http\Controllers\Admin\JobListingController as AdminJobListingController;
use App\Http\Controllers\CareersController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\CheckoutGateController;
use App\Http\Controllers\BuyNowController;

// ── Sitemap ───────────────────────────────────────────────────────────────────
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// ── Public ────────────────────────────────────────────────────────────────────
Route::get('/',            [HomeController::class, 'index'])->name('home');
Route::get('/about',       [HomeController::class, 'about'])->name('about');
Route::get('/contact',     [HomeController::class, 'contact'])->name('contact');
Route::post('/contact',    [HomeController::class, 'sendContact'])->name('contact.send');
Route::get('/faqs',        [HomeController::class, 'faqs'])->name('faqs');
Route::get('/privacy-policy', [HomeController::class, 'privacyPolicy'])->name('privacy-policy');
Route::get('/terms',          [HomeController::class, 'termsConditions'])->name('terms');

// ── Coupons (public AJAX) ─────────────────────────────────────────────────────
Route::post('/cart/coupon/apply',  [CouponController::class, 'apply'])->name('coupon.apply')->middleware('throttle:5,1');
Route::post('/cart/coupon/remove', [CouponController::class, 'remove'])->name('coupon.remove');

// ── Careers ───────────────────────────────────────────────────────────────────
Route::get('/careers',                         [CareersController::class, 'index'])->name('careers.index');
Route::get('/careers/{career:slug}',           [CareersController::class, 'show'])->name('careers.show');
Route::get('/careers/{career:slug}/login',     [CareersController::class, 'loginToApply'])->name('careers.login-to-apply');
Route::post('/careers/{career:slug}/apply',    [CareersController::class, 'apply'])->name('careers.apply')->middleware(['auth', 'throttle:5,10']);

// ── Shop ──────────────────────────────────────────────────────────────────────
Route::get('/shop',                        [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/suggest',               [ShopController::class, 'suggest'])->name('shop.suggest');
Route::get('/shop/{product}',             [ShopController::class, 'show'])->name('shop.show');
Route::post('/shop/{product}/reviews',    [ReviewController::class, 'store'])->name('shop.reviews.store')->middleware('throttle:10,1');

// ── Categories ────────────────────────────────────────────────────────────────
Route::get('/categories',             [CategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/{category}',  [CategoryController::class, 'show'])->name('categories.show');

// ── Cart ──────────────────────────────────────────────────────────────────────
Route::get('/cart',                        [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/add',                   [CartController::class, 'add'])->name('cart.add')->middleware('throttle:60,1');
Route::patch('/cart/{cartItem}',           [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/{cartItem}',          [CartController::class, 'remove'])->name('cart.remove');
Route::post('/cart/clear',                 [CartController::class, 'clear'])->name('cart.clear');
Route::get('/cart/count',                  [CartController::class, 'count'])->name('cart.count');

// ── M-Pesa callback (public — no auth, no CSRF, Safaricom IPs only) ──────────
Route::post('/mpesa/callback', [MpesaController::class, 'callback'])
    ->name('mpesa.callback')
    ->middleware('mpesa.verify');

// ── Checkout gate (public — no auth required) ─────────────────────────────────
Route::get('/checkout/gate',  [CheckoutGateController::class, 'show'])->name('checkout.gate');
Route::post('/checkout/guest',[CheckoutGateController::class, 'continueAsGuest'])->name('checkout.guest');

// ── Buy It Now (public — guests redirected to gate for login/guest choice) ────
Route::post('/buy-now', [BuyNowController::class, 'store'])->name('buy.now')->middleware('throttle:30,1');

// ── Checkout (guests with session flag OR verified users) ─────────────────────
Route::middleware('checkout')->group(function () {
    Route::get('/checkout',                     [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/checkout/place',              [CheckoutController::class, 'placeOrder'])->name('checkout.place');
    Route::get('/checkout/success',             [CheckoutController::class, 'success'])->name('checkout.success');
    Route::post('/checkout/payment-reference/{order}', [CheckoutController::class, 'submitPaymentReference'])->name('checkout.payment.reference')->middleware('throttle:5,1');
    Route::get('/checkout/waiting/{order}',     [CheckoutController::class, 'waiting'])->name('checkout.waiting');
    Route::get('/mpesa/status/{order}',         [MpesaController::class, 'status'])->name('mpesa.status');
    Route::post('/mpesa/resend/{order}',        [MpesaController::class, 'resend'])->name('mpesa.resend');
});

// ── Auth ──────────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login',     [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login',    [LoginController::class, 'login']);
    Route::get('/register',  [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('/register', [RegisterController::class, 'register']);

    Route::get('/forgot-password',        [ForgotPasswordController::class, 'showForm'])->name('password.request');
    Route::post('/forgot-password',       [ForgotPasswordController::class, 'sendLink'])->name('password.email')->middleware('throttle:5,1');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showForm'])->name('password.reset');
    Route::post('/reset-password',        [ResetPasswordController::class, 'reset'])->name('password.update');
});
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// ── Email verification ─────────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/email/verify',               [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}',   [EmailVerificationController::class, 'verify'])->name('verification.verify')->middleware('signed');
    Route::post('/email/resend',              [EmailVerificationController::class, 'resend'])->name('verification.send')->middleware('throttle:6,1');
});

// ── Google OAuth ───────────────────────────────────────────────────────────────
Route::get('/auth/google',          [SocialAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');
Route::post('/testimonials', [TestimonialController::class, 'store'])->name('testimonials.store')->middleware('throttle:5,1');

// ── Account (auth + verified required) ────────────────────────────────────────
Route::middleware(['auth', 'verified'])->prefix('account')->name('account.')->group(function () {
    Route::get('/',          [AccountController::class, 'dashboard'])->name('dashboard');
    Route::get('/profile',   [AccountController::class, 'profile'])->name('profile');
    Route::post('/profile',  [AccountController::class, 'updateProfile'])->name('profile.update');
    Route::post('/password', [AccountController::class, 'updatePassword'])->name('password.update');
    Route::get('/orders',             [AccountController::class, 'orders'])->name('orders');
    Route::get('/orders/{order}',         [AccountController::class, 'showOrder'])->name('orders.show');
    Route::get('/orders/{order}/receipt', [AccountController::class, 'receipt'])->name('orders.receipt');
    Route::patch('/orders/{order}/cancel', [AccountController::class, 'cancelOrder'])->name('orders.cancel');
    Route::get('/wishlist',         [WishlistController::class, 'index'])->name('wishlist');
    Route::post('/wishlist/toggle', [WishlistController::class, 'toggle'])->name('wishlist.toggle');
    Route::delete('/wishlist/{wishlist}', [WishlistController::class, 'remove'])->name('wishlist.remove');
});

// ── Admin ──────────────────────────────────────────────────────────────────────
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/products',                    [AdminProductController::class, 'index'])->name('products.index');
    Route::get('/products/create',             [AdminProductController::class, 'create'])->name('products.create');
    Route::post('/products/bulk-destroy',      [AdminProductController::class, 'bulkDestroy'])->name('products.bulk-destroy');
    Route::post('/products',                   [AdminProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit',     [AdminProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}',          [AdminProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}',       [AdminProductController::class, 'destroy'])->name('products.destroy');
    Route::patch('/products/{product}/toggle', [AdminProductController::class, 'toggleStatus'])->name('products.toggle');

    Route::get('/categories',                  [AdminCategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/create',           [AdminCategoryController::class, 'create'])->name('categories.create');
    Route::post('/categories/bulk-destroy',    [AdminCategoryController::class, 'bulkDestroy'])->name('categories.bulk-destroy');
    Route::post('/categories',                 [AdminCategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}/edit',  [AdminCategoryController::class, 'edit'])->name('categories.edit');
    Route::put('/categories/{category}',       [AdminCategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}',    [AdminCategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('/orders',                      [AdminOrderController::class, 'index'])->name('orders.index');
    Route::post('/orders/bulk-destroy',        [AdminOrderController::class, 'bulkDestroy'])->name('orders.bulk-destroy');
    Route::get('/orders/{order}',              [AdminOrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/status',     [AdminOrderController::class, 'updateStatus'])->name('orders.status');
    Route::delete('/orders/{order}',           [AdminOrderController::class, 'destroy'])->name('orders.destroy');

    Route::get('/customers',                    [AdminCustomerController::class, 'index'])->name('customers.index');
    Route::post('/customers/bulk-destroy',      [AdminCustomerController::class, 'bulkDestroy'])->name('customers.bulk-destroy');
    Route::get('/customers/{customer}',         [AdminCustomerController::class, 'show'])->name('customers.show');
    Route::delete('/customers/{customer}',      [AdminCustomerController::class, 'destroy'])->name('customers.destroy');

    Route::get('/testimonials',                          [TestimonialController::class, 'adminIndex'])->name('testimonials.index');
    Route::post('/testimonials/bulk-destroy',            [TestimonialController::class, 'bulkDestroy'])->name('testimonials.bulk-destroy');
    Route::patch('/testimonials/{testimonial}/approve',  [TestimonialController::class, 'approve'])->name('testimonials.approve');
    Route::delete('/testimonials/{testimonial}',         [TestimonialController::class, 'destroy'])->name('testimonials.destroy');

    Route::post('/reviews/bulk-destroy',      [ReviewController::class, 'bulkDestroy'])->name('reviews.bulk-destroy');
    Route::patch('/reviews/{review}/approve', [ReviewController::class, 'adminApprove'])->name('reviews.approve');
    Route::delete('/reviews/{review}',        [ReviewController::class, 'adminDestroy'])->name('reviews.destroy');

    Route::get('/contact-messages',                      [AdminContactMessageController::class, 'index'])->name('contact-messages.index');
    Route::post('/contact-messages/bulk-destroy',        [AdminContactMessageController::class, 'bulkDestroy'])->name('contact-messages.bulk-destroy');
    Route::patch('/contact-messages/{message}/read',     [AdminContactMessageController::class, 'toggleRead'])->name('contact-messages.read');
    Route::delete('/contact-messages/{message}',         [AdminContactMessageController::class, 'destroy'])->name('contact-messages.destroy');

    Route::get('/settings',   [AdminSettingsController::class, 'index'])->name('settings.index');
    Route::patch('/settings', [AdminSettingsController::class, 'update'])->name('settings.update');

    Route::get('/admins',                        [AdminUserController::class, 'index'])->name('admins.index');
    Route::post('/admins',                       [AdminUserController::class, 'store'])->name('admins.store')->middleware('throttle:10,1');
    Route::patch('/admins/{user}/promote',       [AdminUserController::class, 'promote'])->name('admins.promote');
    Route::delete('/admins/{admin}',             [AdminUserController::class, 'destroy'])->name('admins.destroy');

    Route::get('/coupons',                     [AdminCouponController::class, 'index'])->name('coupons.index');
    Route::get('/coupons/create',              [AdminCouponController::class, 'create'])->name('coupons.create');
    Route::post('/coupons',                    [AdminCouponController::class, 'store'])->name('coupons.store');
    Route::get('/coupons/bulk-generate',       [AdminCouponController::class, 'bulkGenerateForm'])->name('coupons.bulk-generate');
    Route::post('/coupons/bulk-generate',      [AdminCouponController::class, 'bulkGenerate'])->name('coupons.bulk-generate.store');
    Route::get('/coupons/{coupon}/edit',       [AdminCouponController::class, 'edit'])->name('coupons.edit');
    Route::put('/coupons/{coupon}',            [AdminCouponController::class, 'update'])->name('coupons.update');
    Route::delete('/coupons/{coupon}',         [AdminCouponController::class, 'destroy'])->name('coupons.destroy');
    Route::patch('/coupons/{coupon}/toggle',   [AdminCouponController::class, 'toggle'])->name('coupons.toggle');
    Route::get('/coupons/{coupon}/usage',      [AdminCouponController::class, 'usageLog'])->name('coupons.usage');

    Route::get('/careers',                                     [AdminJobListingController::class, 'index'])->name('careers.index');
    Route::get('/careers/create',                              [AdminJobListingController::class, 'create'])->name('careers.create');
    Route::post('/careers/bulk-destroy',                       [AdminJobListingController::class, 'bulkDestroy'])->name('careers.bulk-destroy');
    Route::post('/careers',                                    [AdminJobListingController::class, 'store'])->name('careers.store');
    Route::get('/careers/{career}/edit',                       [AdminJobListingController::class, 'edit'])->name('careers.edit');
    Route::put('/careers/{career}',                            [AdminJobListingController::class, 'update'])->name('careers.update');
    Route::delete('/careers/{career}',                         [AdminJobListingController::class, 'destroy'])->name('careers.destroy');
    Route::patch('/careers/{career}/toggle',                   [AdminJobListingController::class, 'toggle'])->name('careers.toggle');
    Route::get('/careers/{career}/applications',               [AdminJobListingController::class, 'applications'])->name('careers.applications');
    Route::patch('/careers/applications/{application}/status', [AdminJobListingController::class, 'updateApplicationStatus'])->name('careers.applications.update-status');
    Route::delete('/careers/applications/{application}',       [AdminJobListingController::class, 'destroyApplication'])->name('careers.applications.destroy');
    Route::get('/careers/applications/{application}/resume',   [AdminJobListingController::class, 'downloadResume'])->name('careers.resume');

    Route::get('/search', [\App\Http\Controllers\Admin\SearchController::class, 'search'])->name('search');

    Route::get('/profile',            [\App\Http\Controllers\Admin\ProfileController::class, 'show'])->name('profile');
    Route::post('/profile',           [\App\Http\Controllers\Admin\ProfileController::class, 'update'])->name('profile.update')->middleware('throttle:20,1');
    Route::post('/profile/password',  [\App\Http\Controllers\Admin\ProfileController::class, 'updatePassword'])->name('profile.password')->middleware('throttle:5,1');

    Route::get('/delivery-services',                              [AdminDeliveryServiceController::class, 'index'])->name('delivery-services.index');
    Route::get('/delivery-services/create',                       [AdminDeliveryServiceController::class, 'create'])->name('delivery-services.create');
    Route::post('/delivery-services/bulk-destroy',                [AdminDeliveryServiceController::class, 'bulkDestroy'])->name('delivery-services.bulk-destroy');
    Route::post('/delivery-services',                             [AdminDeliveryServiceController::class, 'store'])->name('delivery-services.store');
    Route::get('/delivery-services/{deliveryService}/edit',       [AdminDeliveryServiceController::class, 'edit'])->name('delivery-services.edit');
    Route::put('/delivery-services/{deliveryService}',            [AdminDeliveryServiceController::class, 'update'])->name('delivery-services.update');
    Route::delete('/delivery-services/{deliveryService}',         [AdminDeliveryServiceController::class, 'destroy'])->name('delivery-services.destroy');
    Route::patch('/delivery-services/{deliveryService}/toggle',   [AdminDeliveryServiceController::class, 'toggleStatus'])->name('delivery-services.toggle');

    Route::get('/pickup-stations',                           [AdminPickupStationController::class, 'index'])->name('pickup-stations.index');
    Route::get('/pickup-stations/create',                    [AdminPickupStationController::class, 'create'])->name('pickup-stations.create');
    Route::post('/pickup-stations/bulk-destroy',             [AdminPickupStationController::class, 'bulkDestroy'])->name('pickup-stations.bulk-destroy');
    Route::post('/pickup-stations',                          [AdminPickupStationController::class, 'store'])->name('pickup-stations.store');
    Route::get('/pickup-stations/{pickupStation}/edit',      [AdminPickupStationController::class, 'edit'])->name('pickup-stations.edit');
    Route::put('/pickup-stations/{pickupStation}',           [AdminPickupStationController::class, 'update'])->name('pickup-stations.update');
    Route::delete('/pickup-stations/{pickupStation}',        [AdminPickupStationController::class, 'destroy'])->name('pickup-stations.destroy');
    Route::patch('/pickup-stations/{pickupStation}/toggle',  [AdminPickupStationController::class, 'toggleStatus'])->name('pickup-stations.toggle');
});
