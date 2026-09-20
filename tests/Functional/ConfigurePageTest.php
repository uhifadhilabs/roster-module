<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Roster Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Enum\LateThreshold;
use Uhifadhi\Roster\Enum\VacancyAnnounce;
use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE CONFIGURE PAGE, OVER REAL HTTP — the three sections, who may save them,
 * and what a save actually stores.
 *
 * A GREEN SERVICE TEST IS NOT A WORKING PAGE. Everything here goes through
 * the router, the registry's per-area gate, the real authorization checker
 * and a real CSRF token read off the rendered form, because those four are
 * where a configure page actually breaks.
 */
final class ConfigurePageTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Station $gate;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->gate = new Station()
            ->setArea($this->area)
            ->setName('north gate post')
            ->setCode('ST-01')
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($this->gate);

        // A post the area registers and the roster does NOT work — the whole
        // point of "a post with no watch is never counted".
        $this->em->persist(new Station()
            ->setArea($this->area)
            ->setName('west outpost')
            ->setCode('ST-02')
            ->setPoint('{"type":"Point","coordinates":[12.25,-5.75]}'));

        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));
        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::READER_EMAIL)->setFirstName('Rafi')->setLastName('Reader'));

        $this->everyAreaRunsTheRoster($this->em);
    }

    private function signIn(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    private function url(string $section): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/roster/'.$section;
    }

    private function watches(): StationWatchService
    {
        $service = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $service);

        return $service;
    }

    private function settings(): RosterSettingsService
    {
        $service = static::getContainer()->get('test_public.'.RosterSettingsService::class);
        self::assertInstanceOf(RosterSettingsService::class, $service);

        return $service;
    }

    /**
     * THE MODULE'S FRONT DOOR. The tile links straight here, so if this is not
     * 200 the catalogue is linking at nothing.
     */
    public function testTheOverviewTabIsTheModulesFrontDoor(): void
    {
        $this->signIn(FixedManageVoter::READER_EMAIL);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster');

        self::assertResponseIsSuccessful();
        // The identity band, on the tab it is byte-identical on.
        self::assertSame(1, $crawler->filter('.factband')->count());
        self::assertStringContainsString('Posts with a watch', $crawler->filter('.factband')->text());
    }

    /**
     * The band says how the park is SET UP, so a post that has not been given
     * a watch is counted in the denominator and not in the numerator — which
     * is the whole of "six of the area's twelve".
     */
    public function testTheBandCountsPostsOnTheBooksAgainstTheAreasOwnRegister(): void
    {
        $this->watches()->addToRoster($this->gate);
        $this->signIn(FixedManageVoter::READER_EMAIL);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster');
        $band = $crawler->filter('.factband')->text();

        self::assertStringContainsString('1', $band);
        self::assertStringContainsString('of the area’s 2', $band);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sections(): iterable
    {
        yield 'rotation' => ['rotation'];
        yield 'watches' => ['watches'];
        yield 'settings' => ['settings'];
    }

    /**
     * EVERY SECTION RENDERS, and every one of them links THIS MODULE's
     * stylesheet — which is the reason all three keep an address of their own
     * rather than being bodies the shell renders inside its own page.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sections')]
    public function testEverySectionRendersAndLinksTheModulesOwnStylesheet(string $section): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $crawler = $this->client->request('GET', $this->url($section));

        self::assertResponseIsSuccessful();
        // The path is matched WITHOUT its digest: AssetMapper content-versions
        // the file, so asserting the bare name would be asserting that
        // versioning is off.
        self::assertMatchesRegularExpression('#bundles/uhifadhiroster/roster[.-][^"]*\.css#', $this->client->getResponse()->getContent() ?: '');
        // It wears the configure page's own heading, not a heading of its own.
        self::assertStringContainsString('configure', $crawler->filter('h1')->text());
    }

    /** The band repeats on every configure section, byte-identical. */
    #[\PHPUnit\Framework\Attributes\DataProvider('sections')]
    public function testTheIdentityBandRepeatsOnEverySection(string $section): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $crawler = $this->client->request('GET', $this->url($section));

        self::assertSame(1, $crawler->filter('.factband')->count());
    }

    /**
     * A READER SEES THE PAGE AND NO SAVE BUTTON. Withheld rather than
     * disabled: a greyed control tells somebody a thing exists and they are
     * not trusted with it, which is a worse product than not offering it.
     */
    public function testAReaderSeesTheSettingsAndIsOfferedNoSave(): void
    {
        $this->signIn(FixedManageVoter::READER_EMAIL);

        $crawler = $this->client->request('GET', $this->url('settings'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ping interval', $crawler->filter('body')->text());
        self::assertSame(0, $crawler->filter('button[type=submit]')->count());
    }

    /**
     * SAVING THE SETTINGS STORES THE SIX. The token is read off the rendered
     * form rather than spelled out here: a test that hardcoded it would still
     * pass the day the page stopped rendering one.
     */
    public function testAManagerSavesTheSettings(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('settings'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', $this->url('settings'), [
            '_token' => $token,
            'ping_interval_minutes' => '15',
            'off_day_has_no_state' => '0',
            'leave_approval_shown' => '1',
            'default_catchment_metres' => '900',
            'late_threshold' => LateThreshold::FourHours->value,
            'vacancy_announce' => VacancyAnnounce::AtTheWatch->value,
        ]);

        self::assertResponseRedirects($this->url('settings'));

        $this->em->clear();
        $settings = $this->settings()->forArea($this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'demo reserve']) ?? throw new \LogicException('The fixture area vanished.'));

        self::assertSame(15, $settings->getPingIntervalMinutes());
        self::assertFalse($settings->offDayHasNoState());
        self::assertTrue($settings->isLeaveApprovalShown());
        self::assertSame(900, $settings->getDefaultCatchmentMetres());
        self::assertSame(LateThreshold::FourHours, $settings->getLateThreshold());
        self::assertSame(VacancyAnnounce::AtTheWatch, $settings->getVacancyAnnounce());
    }

    /** A reader who posts anyway is refused, token or no token. */
    public function testAReaderCannotSaveTheSettings(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('settings'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->signIn(FixedManageVoter::READER_EMAIL);
        $this->client->request('POST', $this->url('settings'), ['_token' => $token, 'ping_interval_minutes' => '5']);

        self::assertResponseStatusCodeSame(403);
    }

    /** A form that did not come from this page is refused. */
    public function testASaveWithoutAValidTokenIsRefused(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $this->client->request('POST', $this->url('settings'), ['_token' => 'not-the-token', 'ping_interval_minutes' => '5']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * SAVING A WATCH STORES THE FOUR COLUMNS THIS MODULE OWNS — and an empty
     * `expects` is a real answer, not a missing one: it declares a post that
     * runs nothing.
     */
    public function testAManagerSavesAPostsWatch(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day', 'night']);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $id = $this->gate->getId();
        $this->client->request('POST', $this->url('watches'), [
            '_token' => $token,
            'expects_'.$id => ['day'],
            'silence_'.$id => '240',
            'offline_'.$id => '2880',
            'catchment_'.$id => '2000',
        ]);

        self::assertResponseRedirects($this->url('watches'));

        $this->em->clear();
        $stored = $this->em->getRepository(StationWatch::class)->findOneBy([]);
        self::assertInstanceOf(StationWatch::class, $stored);
        self::assertSame(['day'], $stored->getExpects());
        self::assertSame(240, $stored->getSilenceWindowMinutes());
        self::assertSame(2880, $stored->getOfflineAfterMinutes());

        // AND THE RING WENT TO THE POST, which is the column verification
        // measures against — the watch's own is retired and unread.
        $station = $stored->getStation();
        self::assertSame(2000, $station->getCatchmentM(), 'Saving the watches writes the ring where it is read.');
    }

    /**
     * A POST DECLARED TO RUN NOTHING. The form sends no `expects_*` at all,
     * which has to mean "none" and not "leave it alone" — otherwise a watch
     * could never be emptied.
     */
    public function testAWatchCanBeEmptied(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day', 'night']);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $id = $this->gate->getId();
        $this->client->request('POST', $this->url('watches'), [
            '_token' => $token,
            'silence_'.$id => '120',
            'offline_'.$id => '1440',
            'catchment_'.$id => '1500',
        ]);

        $this->em->clear();
        $stored = $this->em->getRepository(StationWatch::class)->findOneBy([]);
        self::assertInstanceOf(StationWatch::class, $stored);
        self::assertSame([], $stored->getExpects());
        self::assertTrue($stored->expectsNothing());
    }

    /**
     * A post the area registers and nobody put on the roster's books is NAMED
     * on the page rather than silently missing — "a post with no watch is
     * never counted" is only trustworthy if the page says which posts it
     * means.
     */
    public function testTheRotationSectionNamesThePostsThatRunNothing(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $crawler = $this->client->request('GET', $this->url('rotation'));

        self::assertStringContainsString('west outpost', $crawler->filter('body')->text());
        self::assertStringContainsString('never a hole', $crawler->filter('body')->text());
    }

    /**
     * WHERE AN AREA HAS PARKED THIS MODULE, EVERY PAGE HERE IS 404 — the
     * registry's gate, not this module's, and 404 rather than 403 because a
     * parked module is not withheld: the area is not running it.
     */
    public function testAnAreaThatDoesNotRunTheRosterHasNoRosterPages(): void
    {
        $elsewhere = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[13.2,-6.8],[13.5,-6.8],[13.5,-6.5],[13.2,-6.5],[13.2,-6.8]]]]}',
        );
        $this->em->persist($elsewhere);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $this->client->request('GET', '/areas/'.$elsewhere->getUuidString().'/modules/roster');

        self::assertResponseStatusCodeSame(404);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The framework's debug error handler is registered during the test
        // and never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }
}
