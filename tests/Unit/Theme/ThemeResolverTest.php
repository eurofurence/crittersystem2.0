<?php

namespace App\Tests\Unit\Theme;

use App\Entity\Settings;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Theme\ThemeCatalog;
use App\Theme\ThemeResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ThemeResolverTest extends TestCase
{
    private function userWithTheme(?string $themeSlug): User
    {
        $user = new User();
        $settings = new Settings($user);
        $settings->setTheme($themeSlug);
        $user->setSettings($settings);

        return $user;
    }

    private function resolver(
        ?string $queryTheme = null,
        ?User $user = null,
        ?string $adminDefault = null,
    ): ThemeResolver {
        $stack = new RequestStack();
        $request = Request::create('/'.($queryTheme !== null ? '?theme='.$queryTheme : ''));
        $stack->push($request);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $config = $this->createStub(EventConfigStore::class);
        $config->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null) => $key === EventConfigStore::KEY_DEFAULT_THEME ? ($adminDefault ?? $default) : $default,
        );

        return new ThemeResolver(new ThemeCatalog(), $config, $security, $stack);
    }

    public function testFallsBackToFirstCatalogEntryWhenNothingIsSet(): void
    {
        self::assertSame('default', $this->resolver()->resolve()->slug);
    }

    public function testAdminDefaultIsUsedWhenUserHasNoPreference(): void
    {
        self::assertSame('eurofurence', $this->resolver(adminDefault: 'eurofurence')->resolve()->slug);
    }

    public function testUserPreferenceOverridesAdminDefault(): void
    {
        $user = $this->userWithTheme('dark');
        self::assertSame('dark', $this->resolver(user: $user, adminDefault: 'eurofurence')->resolve()->slug);
    }

    public function testQueryParamOverridesEverything(): void
    {
        $user = $this->userWithTheme('dark');
        self::assertSame('eurofurence', $this->resolver(queryTheme: 'eurofurence', user: $user, adminDefault: 'dark')->resolve()->slug);
    }

    public function testUnknownQuerySlugIsIgnored(): void
    {
        self::assertSame('default', $this->resolver(queryTheme: 'nope')->resolve()->slug);
    }

    public function testUnknownUserOrAdminSlugFallsThroughCleanly(): void
    {
        $user = $this->userWithTheme('was-removed');
        self::assertSame('dark', $this->resolver(user: $user, adminDefault: 'dark')->resolve()->slug);
    }
}
