<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\ShareInertiaData;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ShareInertiaData::class)]
class ShareInertiaDataMiddlewareTest extends MiddlewareTestAbstract
{
    private function createTestRoute(): string
    {
        return Route::get('/test-route', function () {
            return Inertia::render('Welcome');
        })->middleware([StartSession::class, ShareInertiaData::class])->uri;
    }

    public function test_shares_hidden_nav_items_of_the_authenticated_user(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $data->user->hidden_nav_items = ['calendar', 'tags'];
        $data->user->save();
        $route = $this->createTestRoute();
        Passport::actingAs($data->user);

        // Act
        $response = $this->get($route);

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.hidden_nav_items', ['calendar', 'tags'])
        );
    }

    public function test_shares_empty_hidden_nav_items_by_default(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $route = $this->createTestRoute();
        Passport::actingAs($data->user);

        // Act
        $response = $this->get($route);

        // Assert
        $response->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.hidden_nav_items', [])
        );
    }
}
