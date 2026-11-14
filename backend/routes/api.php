<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ItemPartsController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PublicFeedbackController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkItemController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| All routes here are served under /api/v1 (see bootstrap/app.php).
*/

Route::get('/', fn () => [
    'name' => config('app.name'),
    'api_version' => 'v1',
])->name('api.root');

Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('register', RegisterController::class)->name('auth.register');
        Route::post('login', [SessionController::class, 'store'])->name('auth.login');
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->name('auth.password.forgot');
        Route::post('reset-password', [PasswordController::class, 'reset'])->name('auth.password.reset');
    });

    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')->middleware('throttle:auth')->name('auth.verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy'])->name('auth.logout');
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:auth')->name('auth.verification.resend');
    });
});

// A page shared by link: readable without signing in.
Route::get('public/pages/{token}', [PageController::class, 'publicShow'])
    ->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:auth')->name('pages.public');

// A project's public feedback form: no sign-in, a few submissions per minute per address.
Route::prefix('public/feedback/{token}')->where(['token' => '[A-Za-z0-9]{20,64}'])->group(function () {
    Route::get('/', [PublicFeedbackController::class, 'show'])->middleware('throttle:auth')->name('feedback.public.show');
    Route::post('/', [PublicFeedbackController::class, 'store'])->middleware('throttle:public-feedback')->name('feedback.public.store');
});

Route::get('invitations/{token}', [InvitationController::class, 'preview'])
    ->middleware('throttle:auth')->name('invitations.preview');

Route::middleware(['auth:sanctum', 'throttle:api', 'token.scope'])->group(function () {
    Route::get('me', [MeController::class, 'show'])->name('me.show');
    Route::patch('me', [MeController::class, 'update'])->name('me.update');

    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    // Everything below acts inside one workspace; non-members get 404.
    Route::prefix('workspaces/{workspace}')->middleware('workspace')->group(function () {
        Route::get('/', [WorkspaceController::class, 'show'])->name('workspaces.show');
        Route::patch('/', [WorkspaceController::class, 'update'])->name('workspaces.update');
        Route::delete('/', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');

        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::patch('members/{user}', [MemberController::class, 'update'])->whereNumber('user')->name('members.update');
        Route::delete('members/{user}', [MemberController::class, 'destroy'])->whereNumber('user')->name('members.destroy');

        Route::get('invitations', [InvitationController::class, 'index'])->name('workspace-invitations.index');
        Route::post('invitations', [InvitationController::class, 'store'])->name('workspace-invitations.store');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->whereNumber('invitation')->name('workspace-invitations.destroy');

        Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
        Route::post('agents', [AgentController::class, 'store'])->name('agents.store');
        Route::patch('agents/{agent}', [AgentController::class, 'update'])->whereNumber('agent')->name('agents.update');
        Route::delete('agents/{agent}', [AgentController::class, 'destroy'])->whereNumber('agent')->name('agents.destroy');
        Route::get('agents/{agent}/tokens', [AgentController::class, 'tokens'])->whereNumber('agent')->name('agent-tokens.index');
        Route::post('agents/{agent}/tokens', [AgentController::class, 'createToken'])->whereNumber('agent')->name('agent-tokens.store');
        Route::delete('agents/{agent}/tokens/{token}', [AgentController::class, 'revokeToken'])
            ->whereNumber(['agent', 'token'])->name('agent-tokens.destroy');

        Route::get('webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
        Route::post('webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
        Route::patch('webhooks/{webhook}', [WebhookController::class, 'update'])->whereNumber('webhook')->name('webhooks.update');
        Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->whereNumber('webhook')->name('webhooks.destroy');
        Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->whereNumber('webhook')->name('webhooks.deliveries');
        Route::post('webhooks/{webhook}/ping', [WebhookController::class, 'ping'])->whereNumber('webhook')->name('webhooks.ping');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

        Route::prefix('projects/{project}')->group(function () {
            Route::get('/', [ProjectController::class, 'show'])->name('projects.show');
            Route::patch('/', [ProjectController::class, 'update'])->name('projects.update');
            Route::delete('/', [ProjectController::class, 'destroy'])->name('projects.destroy');

            Route::get('cycles', [ProjectController::class, 'cycles'])->name('projects.cycles');
            Route::put('feedback-form', [FeedbackController::class, 'publicForm'])->name('projects.feedback-form');
            Route::get('states', [StateController::class, 'index'])->name('states.index');
            Route::post('states', [StateController::class, 'store'])->name('states.store');
            Route::patch('states/{state}', [StateController::class, 'update'])->whereNumber('state')->name('states.update');
            Route::delete('states/{state}', [StateController::class, 'destroy'])->whereNumber('state')->name('states.destroy');

            Route::get('labels', [LabelController::class, 'index'])->name('labels.index');
            Route::post('labels', [LabelController::class, 'store'])->name('labels.store');
            Route::patch('labels/{label}', [LabelController::class, 'update'])->whereNumber('label')->name('labels.update');
            Route::delete('labels/{label}', [LabelController::class, 'destroy'])->whereNumber('label')->name('labels.destroy');
        });

        Route::get('ai', [AiController::class, 'status'])->name('ai.status');
        Route::put('ai', [AiController::class, 'configure'])->name('ai.configure');
        Route::post('ai/draft-item', [AiController::class, 'draftItem'])->name('ai.draft-item');
        Route::post('pages/{page}/ai/summary', [AiController::class, 'summarisePage'])->whereNumber('page')->name('ai.summarise-page');
        Route::post('feedback/{feedback}/ai/triage', [AiController::class, 'triageFeedback'])->whereNumber('feedback')->name('ai.triage-feedback');

        Route::get('feedback', [FeedbackController::class, 'index'])->name('feedback.index');
        Route::post('feedback', [FeedbackController::class, 'store'])->name('feedback.store');
        Route::prefix('feedback/{feedback}')->whereNumber('feedback')->group(function () {
            Route::post('accept', [FeedbackController::class, 'accept'])->name('feedback.accept');
            Route::post('decline', [FeedbackController::class, 'decline'])->name('feedback.decline');
            Route::post('duplicate', [FeedbackController::class, 'duplicate'])->name('feedback.duplicate');
            Route::post('snooze', [FeedbackController::class, 'snooze'])->name('feedback.snooze');
            Route::post('reopen', [FeedbackController::class, 'reopen'])->name('feedback.reopen');
        });

        Route::get('search', SearchController::class)->name('search');

        Route::get('pages', [PageController::class, 'index'])->name('pages.index');
        Route::post('pages', [PageController::class, 'store'])->name('pages.store');
        Route::get('pages/{page}', [PageController::class, 'show'])->whereNumber('page')->name('pages.show');
        Route::patch('pages/{page}', [PageController::class, 'update'])->whereNumber('page')->name('pages.update');
        Route::delete('pages/{page}', [PageController::class, 'destroy'])->whereNumber('page')->name('pages.destroy');

        Route::get('views', [SavedViewController::class, 'index'])->name('views.index');
        Route::post('views', [SavedViewController::class, 'store'])->name('views.store');
        Route::patch('views/{view}', [SavedViewController::class, 'update'])->whereNumber('view')->name('views.update');
        Route::delete('views/{view}', [SavedViewController::class, 'destroy'])->whereNumber('view')->name('views.destroy');

        Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::post('inbox/read-all', [InboxController::class, 'readAll'])->name('inbox.read-all');
        Route::patch('inbox/{entry}', [InboxController::class, 'update'])->whereNumber('entry')->name('inbox.update');
        Route::delete('inbox/{entry}', [InboxController::class, 'destroy'])->whereNumber('entry')->name('inbox.destroy');

        // Work items are addressed by key, for example /items/WEB-143.
        Route::get('items', [WorkItemController::class, 'index'])->name('items.index');
        // Declared before items/{item}, so "ready" is not read as an item key.
        Route::get('items/ready', [WorkItemController::class, 'ready'])->name('items.ready');
        Route::post('items', [WorkItemController::class, 'store'])->middleware('idempotent')->name('items.store');

        Route::prefix('items/{item}')->group(function () {
            Route::get('/', [WorkItemController::class, 'show'])->name('items.show');
            Route::patch('/', [WorkItemController::class, 'update'])->name('items.update');
            Route::delete('/', [WorkItemController::class, 'destroy'])->name('items.destroy');
            Route::get('activity', [WorkItemController::class, 'activity'])->name('items.activity');
            Route::post('claim', [WorkItemController::class, 'claim'])->name('items.claim');
            Route::delete('claim', [WorkItemController::class, 'release'])->name('items.release');
            Route::post('ask', [WorkItemController::class, 'ask'])->name('items.ask');
            Route::post('ai/split', [AiController::class, 'splitItem'])->name('ai.split-item');
            Route::post('ai/summary', [AiController::class, 'summariseItem'])->name('ai.summarise-item');
            Route::get('runs', [WorkItemController::class, 'runs'])->name('items.runs');
            Route::post('log', [WorkItemController::class, 'log'])->name('items.log');
            Route::post('submit-for-review', [WorkItemController::class, 'submitForReview'])->name('items.submit-for-review');
            Route::put('subscription', [WorkItemController::class, 'subscribe'])->name('items.subscribe');
            Route::delete('subscription', [WorkItemController::class, 'unsubscribe'])->name('items.unsubscribe');

            Route::get('comments', [CommentController::class, 'index'])->name('comments.index');
            Route::post('comments', [CommentController::class, 'store'])->middleware('idempotent')->name('comments.store');
            Route::patch('comments/{comment}', [CommentController::class, 'update'])->whereNumber('comment')->name('comments.update');
            Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->whereNumber('comment')->name('comments.destroy');

            Route::post('checklist', [ItemPartsController::class, 'addChecklistItem'])->name('checklist.store');
            Route::patch('checklist/{entry}', [ItemPartsController::class, 'updateChecklistItem'])->whereNumber('entry')->name('checklist.update');
            Route::delete('checklist/{entry}', [ItemPartsController::class, 'removeChecklistItem'])->whereNumber('entry')->name('checklist.destroy');

            Route::post('links', [ItemPartsController::class, 'addLink'])->name('links.store');
            Route::delete('links/{link}', [ItemPartsController::class, 'removeLink'])->whereNumber('link')->name('links.destroy');

            Route::post('relations', [ItemPartsController::class, 'addRelation'])->name('relations.store');
            Route::delete('relations/{relation}', [ItemPartsController::class, 'removeRelation'])->whereNumber('relation')->name('relations.destroy');

            Route::get('attachments', [AttachmentController::class, 'index'])->name('attachments.index');
            Route::post('attachments', [AttachmentController::class, 'store'])->name('attachments.store');
            Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])->whereNumber('attachment')->name('attachments.download');
            Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('attachment')->name('attachments.destroy');
        });
    });
});
