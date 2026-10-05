<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AccountingReportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\ClosingController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanProductController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavingsController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShareCapitalController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// The original starter page (business type form) now lives at /starter
Route::get('/starter', [HomeController::class, 'index'])->name('home');
Route::post('/generate', [HomeController::class, 'generate'])->name('generate');

// Home: send people to the dashboard (or the login page)
Route::get('/', fn () => redirect()->route('dashboard'));

// ---- Login / logout ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
});

// ---- Everything below needs a signed-in user ----
Route::middleware('auth')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/account', [AuthController::class, 'editAccount'])->name('account.edit');
    Route::put('/account', [AuthController::class, 'updateAccount'])->name('account.update');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('module:dashboard')->name('dashboard');

    // Members (create/edit routes first so "create" is not read as an id)
    Route::middleware('module:members,manage')->group(function () {
        Route::get('/members/create', [MemberController::class, 'create'])->name('members.create');
        Route::post('/members', [MemberController::class, 'store'])->name('members.store');
        Route::get('/members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
        Route::put('/members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::post('/members/{member}/toggle', [MemberController::class, 'toggle'])->name('members.toggle');
    });
    Route::middleware('module:members')->group(function () {
        Route::get('/members', [MemberController::class, 'index'])->name('members.index');
        Route::get('/members/{member}', [MemberController::class, 'show'])->name('members.show');
        Route::get('/members/{member}/ledger', [MemberController::class, 'ledger'])->name('members.ledger');
    });

    // Share capital
    Route::get('/share-capital', [ShareCapitalController::class, 'index'])->middleware('module:share')->name('share.index');
    Route::get('/share-capital/{member}/ledger', [ShareCapitalController::class, 'ledger'])->middleware('module:share')->name('share.ledger');
    Route::middleware('module:share,manage')->group(function () {
        Route::post('/share-capital/payment', [ShareCapitalController::class, 'payment'])->name('share.payment');
        Route::post('/share-capital/{member}/subscription', [ShareCapitalController::class, 'subscription'])->name('share.subscription');
    });

    // Teller window: deposits, withdrawals and loan payments (the store action checks the role for each kind)
    Route::middleware('module:savings')->group(function () {
        Route::get('/savings', [SavingsController::class, 'index'])->name('savings.index');
        Route::get('/savings/account/{member}', [SavingsController::class, 'account'])->name('savings.account');
        Route::get('/savings/{member}/statement', [SavingsController::class, 'statement'])->name('savings.statement');
        Route::post('/savings', [SavingsController::class, 'store'])->name('savings.store');
    });

    // Loans
    Route::middleware('module:loans,manage')->group(function () {
        Route::get('/loans/create', [LoanController::class, 'create'])->name('loans.create');
        Route::post('/loans', [LoanController::class, 'store'])->name('loans.store');
    });
    Route::middleware('module:loans')->group(function () {
        Route::get('/loans', [LoanController::class, 'index'])->name('loans.index');
        Route::get('/loans/{loan}', [LoanController::class, 'show'])->name('loans.show');
        Route::get('/loans/{loan}/ledger', [LoanController::class, 'ledger'])->name('loans.ledger');
    });
    Route::post('/loans/{loan}/approve', [LoanController::class, 'approve'])->middleware('module:loans,approve')->name('loans.approve');
    Route::post('/loans/{loan}/reject', [LoanController::class, 'reject'])->middleware('module:loans,approve')->name('loans.reject');
    Route::post('/loans/{loan}/release', [LoanController::class, 'release'])->middleware('module:loans,disburse')->name('loans.release');

    // Payment collection: the teller workspace, receipts and their register
    Route::middleware('module:collections')->group(function () {
        Route::get('/collections', [CollectionController::class, 'index'])->name('collections.index');
        Route::get('/collections/members', [CollectionController::class, 'members'])->name('collections.members');
        Route::get('/collections/suggest', [CollectionController::class, 'suggest'])->name('collections.suggest');
        Route::get('/collections/member/{member}', [CollectionController::class, 'overview'])->name('collections.overview');
        Route::get('/collections/{collection}', [CollectionController::class, 'show'])->name('collections.show')->whereNumber('collection');
        Route::get('/collections/{collection}/receipt', [CollectionController::class, 'receipt'])->name('collections.receipt')->whereNumber('collection');
    });
    Route::middleware('module:collections,manage')->group(function () {
        Route::get('/collections/new', [CollectionController::class, 'create'])->name('collections.create');
        Route::post('/collections', [CollectionController::class, 'store'])->name('collections.store');
    });
    // Cancelling is checked inside the controller (a draft needs "manage", a posted receipt needs "reverse")
    Route::post('/collections/{collection}/cancel', [CollectionController::class, 'cancel'])->middleware('module:collections')->name('collections.cancel')->whereNumber('collection');

    // Loan products (create/edit routes first so "create" is not read as an id)
    Route::middleware('module:loan_products,manage')->group(function () {
        Route::get('/loan-products/create', [LoanProductController::class, 'create'])->name('loan-products.create');
        Route::post('/loan-products', [LoanProductController::class, 'store'])->name('loan-products.store');
        Route::get('/loan-products/{loanProduct}/edit', [LoanProductController::class, 'edit'])->name('loan-products.edit');
        Route::put('/loan-products/{loanProduct}', [LoanProductController::class, 'update'])->name('loan-products.update');
        Route::post('/loan-products/{loanProduct}/toggle', [LoanProductController::class, 'toggle'])->name('loan-products.toggle');
        Route::delete('/loan-products/{loanProduct}', [LoanProductController::class, 'destroy'])->name('loan-products.destroy');
    });
    Route::middleware('module:loan_products')->group(function () {
        Route::get('/loan-products', [LoanProductController::class, 'index'])->name('loan-products.index');
        Route::get('/loan-products/{loanProduct}', [LoanProductController::class, 'show'])->name('loan-products.show');
        Route::get('/loan-products/{loanProduct}/compute', [LoanProductController::class, 'compute'])->name('loan-products.compute');
    });

    // Cash receipts and disbursements
    Route::get('/cash-receipts', [CashController::class, 'receipts'])->middleware('module:receipts')->name('receipts.index');
    Route::post('/cash-receipts', [CashController::class, 'storeReceipt'])->middleware('module:receipts,manage')->name('receipts.store');
    Route::get('/cash-disbursements', [CashController::class, 'disbursements'])->middleware('module:disbursements')->name('disbursements.index');
    Route::post('/cash-disbursements', [CashController::class, 'storeDisbursement'])->middleware('module:disbursements,manage')->name('disbursements.store');
    Route::get('/members/{member}/loans-json', [CashController::class, 'memberLoans'])->middleware('module:receipts')->name('members.loans');

    // Transaction ledger
    Route::middleware('module:ledger')->group(function () {
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/export', [TransactionController::class, 'export'])->name('transactions.export');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt'])->name('transactions.receipt');
    });
    Route::post('/transactions/{transaction}/void', [TransactionController::class, 'void'])->middleware('module:ledger,void')->name('transactions.void');

    // Accounting and reports
    // Accounting: statements, ledger, journal and chart of accounts
    Route::middleware('module:accounting')->prefix('accounting')->name('accounting.')->group(function () {
        Route::get('/', [AccountingController::class, 'index'])->name('index');
        Route::get('/trial-balance', [AccountingController::class, 'trialBalanceReport'])->name('trial');
        Route::get('/income-statement', [AccountingController::class, 'incomeStatement'])->name('income');
        Route::get('/balance-sheet', [AccountingController::class, 'balanceSheet'])->name('balance');
        Route::get('/ledger', [AccountingController::class, 'ledger'])->name('ledger');
        Route::get('/journal', [JournalController::class, 'index'])->name('journal.index');
        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');

        // Management reports
        Route::get('/reports', [AccountingReportController::class, 'hub'])->name('reports');
        Route::get('/cash-flow', [AccountingReportController::class, 'cashFlow'])->name('cashflow');
        Route::get('/changes-in-equity', [AccountingReportController::class, 'equity'])->name('equity');
        Route::get('/highlights', [AccountingReportController::class, 'highlights'])->name('highlights');
        Route::get('/loan-aging', [AccountingReportController::class, 'aging'])->name('aging');
        Route::get('/schedules', [AccountingReportController::class, 'schedules'])->name('schedules');
        Route::get('/general-journal', [AccountingReportController::class, 'generalJournal'])->name('daybook');
        Route::get('/cash-book', [AccountingReportController::class, 'cashBook'])->name('cashbook');
        Route::get('/closing', [ClosingController::class, 'index'])->name('closing');
    });
    Route::middleware('module:accounting,close')->prefix('accounting')->name('accounting.')->group(function () {
        Route::post('/closing/{year}/close', [ClosingController::class, 'close'])->name('closing.close')->whereNumber('year');
        Route::post('/closing/{year}/reopen', [ClosingController::class, 'reopen'])->name('closing.reopen')->whereNumber('year');
    });
    Route::middleware('module:accounting,manage')->prefix('accounting')->name('accounting.')->group(function () {
        Route::get('/journal/create', [JournalController::class, 'create'])->name('journal.create');
        Route::post('/journal', [JournalController::class, 'store'])->name('journal.store');
        Route::post('/journal/{entry}/reverse', [JournalController::class, 'reverse'])->name('journal.reverse')->whereNumber('entry');
        Route::get('/accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('/accounts/{account}/edit', [AccountController::class, 'edit'])->name('accounts.edit');
        Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::post('/accounts/{account}/toggle', [AccountController::class, 'toggle'])->name('accounts.toggle');
        Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    });
    Route::get('/accounting/journal/{entry}', [JournalController::class, 'show'])->middleware('module:accounting')->name('accounting.journal.show')->whereNumber('entry');
    Route::middleware('module:reports')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    // User management (Super Admin)
    Route::middleware('module:users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
    });

    // Settings (Super Admin)
    Route::get('/settings', [SettingController::class, 'edit'])->middleware('module:settings')->name('settings.edit');
    Route::put('/settings', [SettingController::class, 'update'])->middleware('module:settings,manage')->name('settings.update');
});
