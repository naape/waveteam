<?php

use App\Enums\Portal;
use App\Http\Controllers\Applicant\ApplicationController;
use App\Http\Controllers\Applicant\DashboardController as ApplicantDashboard;
use App\Http\Controllers\Applicant\DocumentController;
use App\Http\Controllers\Applicant\PoliceClearanceController;
use App\Http\Controllers\Applicant\ProfileController;
use App\Http\Controllers\Applicant\VacancyController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Auth\AccountSecurityController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\PortalLoginController;
use App\Http\Controllers\Auth\RegistrationController;
use App\Http\Controllers\Organization\DashboardController as OrganizationDashboard;
use App\Http\Controllers\Organization\ProfileController as OrganizationProfile;
use App\Http\Controllers\Organization\QuotationController;
use App\Http\Controllers\Organization\ServiceRequestController;
use App\Http\Controllers\OrganizationFileController;
use App\Http\Controllers\PolicyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
| The admin panel (/admin) is registered separately by Filament.
*/

Route::view('/', 'home')->name('home');

Route::get('/{slug}', [PolicyController::class, 'show'])
    ->whereIn('slug', ['privacy-policy', 'terms', 'data-retention'])
    ->name('policy');

/*
|--------------------------------------------------------------------------
| Portal authentication (one set of routes per portal)
|--------------------------------------------------------------------------
| "portal" is passed as a route default and bound to the Portal enum.
*/

foreach (Portal::cases() as $portal) {
    Route::prefix($portal->value)
        ->name($portal->routePrefix().'.')
        ->group(function () use ($portal) {
            Route::view('/', 'portals.'.$portal->routePrefix())->name('landing');

            Route::middleware('guest')->group(function () use ($portal) {
                Route::get('login', [PortalLoginController::class, 'show'])->name('login')->defaults('portal', $portal->value);
                Route::post('login', [PortalLoginController::class, 'store'])->defaults('portal', $portal->value);

                Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request')->defaults('portal', $portal->value);
                Route::post('forgot-password', [PasswordResetController::class, 'email'])->name('password.email')
                    ->middleware('throttle:password-email')->defaults('portal', $portal->value);
            });
        });
}

Route::middleware('guest')->group(function () {
    Route::get('applicants/register', [RegistrationController::class, 'showApplicant'])->name('applicant.register');
    Route::post('applicants/register', [RegistrationController::class, 'storeApplicant'])->middleware('throttle:registration');

    Route::get('organizations/register', [RegistrationController::class, 'showOrganization'])->name('organization.register');
    Route::post('organizations/register', [RegistrationController::class, 'storeOrganization'])->middleware('throttle:registration');

    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.update')->middleware('throttle:password-reset');
});

/*
|--------------------------------------------------------------------------
| Authenticated (any portal user)
|--------------------------------------------------------------------------
*/

Route::post('logout', [PortalLoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'portal:any'])->group(function () {
    Route::get('email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:verification'])->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:verification')->name('verification.send');

    Route::middleware('verified')->prefix('account')->name('account.')->group(function () {
        Route::get('security', [AccountSecurityController::class, 'show'])->name('security');
        Route::put('password', [AccountSecurityController::class, 'updatePassword'])->name('password')->middleware('throttle:account-security');
        Route::delete('sessions', [AccountSecurityController::class, 'destroyOtherSessions'])->name('sessions.destroy')->middleware('throttle:account-security');
    });
});

/*
|--------------------------------------------------------------------------
| Applicant portal
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'portal:applicant', 'verified'])
    ->prefix('applicants')->name('applicant.')
    ->group(function () {
        Route::get('dashboard', ApplicantDashboard::class)->name('dashboard');

        // Profile wizard
        Route::prefix('profile')->name('profile.')->controller(ProfileController::class)->group(function () {
            Route::get('/', fn () => redirect()->route('applicant.profile.personal'))->name('index');
            Route::get('personal', 'editPersonal')->name('personal');
            Route::put('personal', 'updatePersonal')->middleware('throttle:profile-writes');
            Route::get('skills', 'editSkills')->name('skills');
            Route::put('skills', 'updateSkills');
            Route::get('preferences', 'editPreferences')->name('preferences');
            Route::put('preferences', 'updatePreferences');

            $sections = 'education|certifications|employment|languages|driving-licences|military';
            Route::get('{section}', 'section')->where('section', $sections)->name('section');
            Route::post('{section}', 'storeEntry')->where('section', $sections)->name('section.store')->middleware('throttle:profile-writes');
            Route::put('{section}/{entry}', 'updateEntry')->where(['section' => $sections, 'entry' => '[0-9]+'])->name('section.update');
            Route::delete('{section}/{entry}', 'destroyEntry')->where(['section' => $sections, 'entry' => '[0-9]+'])->name('section.destroy');
        });

        // Documents & police clearance
        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store')->middleware('throttle:uploads');
        Route::get('police-clearance', [PoliceClearanceController::class, 'index'])->name('police-clearance.index');
        Route::post('police-clearance', [PoliceClearanceController::class, 'store'])->name('police-clearance.store')->middleware('throttle:pcc-uploads');

        // Vacancies & applications
        Route::get('vacancies', [VacancyController::class, 'index'])->name('vacancies.index');
        Route::get('vacancies/{vacancy}', [VacancyController::class, 'show'])->name('vacancies.show');
        Route::post('vacancies/{vacancy}/apply', [VacancyController::class, 'apply'])->name('vacancies.apply')->middleware('throttle:applications');
        Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');

        // In-app notifications
        Route::post('notifications/{id}', [NotificationController::class, 'open'])->name('notifications.open');
        Route::post('notifications', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    });

/*
|--------------------------------------------------------------------------
| Secure document viewer (applicants: own files; staff: by permission + 2FA)
|--------------------------------------------------------------------------
*/

// Organisation-side files for staff (same access rules as the client route, plus 2FA).
Route::get('files/{file}', OrganizationFileController::class)
    ->middleware(['auth', 'verified', 'staff.mfa', 'throttle:documents'])
    ->name('files.show');

// Queued report exports (staff only, owner only).
Route::get('reports/{file}', \App\Http\Controllers\ReportDownloadController::class)
    ->middleware(['auth', 'verified', 'staff.mfa', 'throttle:documents'])
    ->name('reports.download');

Route::get('documents/{document}/file', DocumentFileController::class)
    ->middleware(['auth', 'verified', 'staff.mfa', 'throttle:documents'])
    ->name('documents.file');

/*
|--------------------------------------------------------------------------
| Organisation (service request) portal
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'portal:org_contact', 'verified'])
    ->prefix('organizations')->name('organization.')
    ->group(function () {
        Route::get('dashboard', OrganizationDashboard::class)->name('dashboard');

        // Organisation profile & supporting documents
        Route::get('profile', [OrganizationProfile::class, 'edit'])->name('profile');
        Route::put('profile', [OrganizationProfile::class, 'update'])->middleware('throttle:profile-writes');
        Route::post('profile/documents', [OrganizationProfile::class, 'storeDocument'])->name('profile.documents.store')->middleware('throttle:uploads');

        // Service requests
        Route::get('requests', [ServiceRequestController::class, 'index'])->name('requests.index');
        Route::get('requests/new', [ServiceRequestController::class, 'create'])->name('requests.create');
        Route::post('requests', [ServiceRequestController::class, 'store'])->name('requests.store')->middleware('throttle:service-requests');
        Route::get('requests/{serviceRequest}', [ServiceRequestController::class, 'show'])->name('requests.show');
        Route::post('requests/{serviceRequest}/messages', [ServiceRequestController::class, 'message'])->name('requests.messages.store')->middleware('throttle:messages');
        Route::post('requests/{serviceRequest}/cancel', [ServiceRequestController::class, 'cancel'])->name('requests.cancel')->middleware('throttle:account-security');
        Route::post('quotations/{quotation}/respond', [QuotationController::class, 'respond'])->name('quotations.respond')->middleware('throttle:account-security');

        // Secure file downloads (attachments, quotation PDFs, supporting documents)
        Route::get('files/{file}', OrganizationFileController::class)->name('files.show')->middleware('throttle:documents');

        // In-app notifications
        Route::post('notifications/{id}', [NotificationController::class, 'open'])->name('notifications.open');
        Route::post('notifications', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    });
