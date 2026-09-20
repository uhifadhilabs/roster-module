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

namespace Uhifadhi\Roster\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Controller\StationConfigureController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Enum\LateThreshold;
use Uhifadhi\Roster\Enum\RestRule;
use Uhifadhi\Roster\Enum\RotationPreset;
use Uhifadhi\Roster\Enum\VacancyAnnounce;
use Uhifadhi\Roster\Model\RotationDraft;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Service\RosteredPeople;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Service\RotationEditor;
use Uhifadhi\Roster\Service\RotationGenerator;
use Uhifadhi\Roster\Service\RotationPreview;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;

/**
 * THE THREE SECTIONS OF THE ROSTER'S CONFIGURE PAGE — Rotation, Watches,
 * Settings — each at an address of its own so it can link the stylesheet it
 * draws with.
 *
 * EACH ONE STILL BELONGS TO THE CONFIGURE PAGE. It wears the page's heading
 * and the page's section strip, and the Configure action stays lit on it,
 * because the template adopts the same frame. Nothing here draws navigation,
 * and there is no way back other than the strip, the lit Configure and the
 * crumb.
 *
 * THEY SIT BESIDE /configure AND NEVER UNDER IT. The shell owns
 * `/areas/{uuid}/modules/{slug}/configure/{section}` and matches any
 * lowercase slug, so a section screen addressed under that prefix would race
 * the frame's own route and the winner would depend on import order. A
 * section's own address is one segment under the module instead, and the
 * shell's bare `/configure` answers 302 to the first of them — one rule,
 * both shapes.
 *
 * EVERY WRITE RIDES ON "roster.manage" AND A CSRF TOKEN. The permission check
 * is in CODE rather than an #[IsGranted] attribute, so the class stays
 * loadable in an installation with no security-bundle attributes to resolve —
 * and the whole controller is registered only where SecurityBundle is in the
 * kernel, so a security-less installation gets no routes at all rather than
 * open write endpoints.
 */
#[Route(defaults: ['_uhifadhi_module' => RosterModuleProvider::SLUG])]
final class RosterConfigureController
{
    public const string ROTATION_ROUTE = 'roster_configure_rotation';
    public const string WATCHES_ROUTE = 'roster_configure_watches';
    public const string SETTINGS_ROUTE = 'roster_configure_settings';

    /** PUT A POST ON THE BOOKS — the door the Watches section's add row opens. */
    public const string ADD_TO_ROSTER_ROUTE = 'roster_configure_watches_add';

    /** DECLARE A ROTATION — the door the page header's "New rotation" opens. */
    public const string DECLARE_ROTATION_ROUTE = 'roster_configure_rotation_declare';

    /** The query that opens the rotation section on a blank declaration. */
    public const string NEW_QUERY = 'new';

    public const string SAVE_ROTATION_ROUTE = 'roster_configure_rotation_save';
    public const string GENERATE_ROTATION_ROUTE = 'roster_configure_rotation_generate';
    public const string SAVE_WATCHES_ROUTE = 'roster_configure_watches_save';
    public const string SAVE_SETTINGS_ROUTE = 'roster_configure_settings_save';

    /**
     * WHAT A PERSON MUST HOLD TO CHANGE HOW THIS AREA'S ROSTER IS SET UP.
     *
     * Declared by the module provider against this exact constant, and read
     * here. The string is never retyped: a permission whose declaration and
     * whose check differ by one character is a screen nobody can open and an
     * admin checkbox that grants nothing.
     */
    public const string MANAGE_PERMISSION = 'roster.manage';

    /** One token id for the whole configure page; each form carries it. */
    public const string CSRF_TOKEN_ID = 'roster_configure';

    /**
     * THE ONE WORD A DOOR ON THE AREA'S OWN STATIONS CARD SENDS, so that it
     * returns to the card it was pressed on. It is a name and not an
     * address: see {@see backFrom()}.
     */
    public const string BACK_TO_THE_STATION = 'station';

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly RosterIdentityService $identity,
        private readonly RosterSettingsService $settings,
        private readonly ShiftVocabularyService $shifts,
        private readonly StationWatchService $watches,
        private readonly StationRepository $stations,
        // WHO STANDS AT A POST — the ring a new rotation starts with draws
        // on the people the AREA posted there, which is the only list this
        // module could honestly seed a pool from.
        private readonly PostingRepository $postings,
        // THE POST'S OWN CATCHMENT is the column verification measures a
        // ping against, and the area owns the verb that writes it.
        private readonly StationService $stationDesk,
        private readonly CheckInStatusService $checkInStatuses,
        private readonly RosteredPeople $people,
        private readonly RotationEditor $editor,
        private readonly RotationPreview $preview,
        private readonly RotationGenerator $generator,
        private readonly RotationRepository $rotations,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/areas/{uuid}/modules/roster/rotation', name: self::ROTATION_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function rotation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $rotations = $this->rotations->findByArea($area);
        $chosen = $this->chosenRotation($rotations, $request->query->get('rotation'));

        return new Response($this->twig->render('@UhifadhiRoster/configure/rotation.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'rotations' => $rotations,
            'chosen' => $chosen,
            'shifts' => $this->shifts->openFor($area),
            'candidates' => $this->people->rosteredIn($area),
            'restRules' => RestRule::cases(),
            'horizons' => RotationEditor::HORIZONS,
            'previewFeed' => $this->preview,
            'previewScope' => null === $chosen ? null : RotationPreview::scopeFor($chosen),
            'previewMonth' => $this->people->monthOf($request->query->get('month')),
            'draft' => null === $chosen ? null : self::draftOf($chosen),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            // The posts the area registers that run no ring at all. Named
            // rather than counted: "a post with no rotation is never counted,
            // reported on or called a hole" is only trustworthy if the page
            // says which posts it means.
            'postsWithoutARotation' => $this->postsWithoutARotation($area),
            // THE BLANK DECLARATION, opened by the page header's one accent
            // action. It is a STATE of this section rather than a page of
            // its own: a rotation is declared and then edited, and sending
            // somebody to a second address to do the first half would be
            // two screens for one act.
            'declaring' => $request->query->has(self::NEW_QUERY) || [] === $rotations,
            // WHAT A RING MAY BE DECLARED FOR: a post already on the books.
            // A post with no watch has no shifts for a ring to repeat, so
            // it is not offered — the Watches section is where that is
            // fixed, and the section strip is two clicks away.
            'declarable' => $this->declarablePosts($area),
            'presets' => RotationPreset::cases(),
            'mayManage' => $this->authorization->isGranted(self::MANAGE_PERMISSION, $area),
        ]));
    }

    /**
     * PUT A POST ON THE ROSTER'S BOOKS — the one write that makes this
     * module work a post at all.
     *
     * IT IS DELIBERATE AND IT IS SMALL. The post arrives with the area's
     * default ring and no shift at all, which is exactly the honest state:
     * this module now keeps the post, and has not yet been told what it
     * stands. Everything after that is the Watches row itself.
     *
     * TWO DOORS, ONE WRITE. The Watches section's add row and the Roster
     * block on the area's own Stations configure card both post here, and
     * the second says so with `back` so it returns to the card it was
     * pressed on rather than to a page nobody asked for.
     */
    #[Route('/areas/{uuid}/modules/roster/watches/add', name: self::ADD_TO_ROSTER_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function addToRoster(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $station = $this->stationIn($area, $request->request->get('station'));
        if (null === $station) {
            self::flash($request, 'error', 'That post is not one of this area’s, so it cannot go on this area’s books.');

            return $this->backFrom($request, $area, null);
        }

        $this->watches->addToRoster($station);

        self::flash($request, 'success', \sprintf(
            '%s is on the roster’s books. Name the watches it stands, and it starts being counted.',
            $station->getName(),
        ));

        return $this->backFrom($request, $area, $station);
    }

    /**
     * DECLARE A ROTATION — what the page header's "New rotation" writes.
     *
     * THE RING IS THE PRESET FILLED WITH THE POST'S OWN WATCHES, and the
     * pool is whoever the area has posted there. Both are a starting point
     * and both are editable the moment the redirect lands: the point of the
     * door is that a post with a watch stops being a post that can never
     * generate anything.
     */
    #[Route('/areas/{uuid}/modules/roster/rotation/new', name: self::DECLARE_ROTATION_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function declareRotation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $station = $this->stationIn($area, $request->request->get('station'));
        $preset = RotationPreset::tryFrom((string) $request->request->get('preset')) ?? RotationPreset::OneOfEachThenOff;

        if (null === $station) {
            self::flash($request, 'error', 'A rotation stands at a post, and that one is not on this area’s books.');

            return $this->backTo(self::ROTATION_ROUTE, $area);
        }

        $watch = $this->watches->forStation($station);
        if (null === $watch) {
            self::flash($request, 'error', \sprintf('%s is not on the roster’s books yet, so there is no watch for a ring to repeat.', $station->getName()));

            return $this->backTo(self::ROTATION_ROUTE, $area);
        }

        $pool = [];
        foreach ($this->postings->findStandingByStation($station) as $posting) {
            $person = $posting->getPerson();
            if (null !== $person) {
                $pool[] = $person;
            }
        }

        try {
            $teamName = trim((string) $request->request->get('team_name'));

            $rotation = '' === $teamName
                ? $this->editor->declareForPost($station, $watch->getExpects(), $preset, $pool)
                : $this->editor->declareForTeam($teamName, $station, $watch->getExpects(), $preset, $pool);
        } catch (\InvalidArgumentException $refused) {
            self::flash($request, 'error', $refused->getMessage());

            return $this->backTo(self::ROTATION_ROUTE, $area);
        }

        self::flash($request, 'success', \sprintf(
            'The rotation is declared — a %d-day ring drawing on %d. Generate it, and the days it produces reach the plan sheet and the handsets.',
            $rotation->getCycle()->length(),
            $rotation->getPool()->count(),
        ));

        return $this->backTo(self::ROTATION_ROUTE, $area, ['rotation' => $rotation->getUuid()->toRfc4122()]);
    }

    #[Route('/areas/{uuid}/modules/roster/watches', name: self::WATCHES_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function watches(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $watches = $this->watches->forArea($area);

        return new Response($this->twig->render('@UhifadhiRoster/configure/watches.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'watches' => $watches,
            // THE AREA'S DEFAULT RING, for a post that carries none of its
            // own — the field shows what verification would actually use.
            'settings' => $this->settings->forArea($area),
            'pools' => $this->poolSizes($watches),
            'shifts' => $this->shifts->openFor($area),
            // The area's other posts — what "Add a post to the roster" offers.
            'postsOffTheBooks' => $this->postsOffTheBooks($area, $watches),
            'mayManage' => $this->authorization->isGranted(self::MANAGE_PERMISSION, $area),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    #[Route('/areas/{uuid}/modules/roster/settings', name: self::SETTINGS_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function settings(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return new Response($this->twig->render('@UhifadhiRoster/configure/settings.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'settings' => $this->settings->forArea($area),
            // THE AREA'S LIST, READ AND NEVER WRITTEN. A check-in belongs to
            // the area and so do the statuses it can carry; this section
            // shows them because this is where somebody configuring the
            // roster looks for them, and links to where they are edited.
            'checkInStatuses' => $this->checkInStatuses->offeredBy($area),
            'shifts' => $this->shifts->forArea($area),
            'lateThresholds' => LateThreshold::cases(),
            'vacancyAnnouncements' => VacancyAnnounce::cases(),
            'mayManage' => $this->authorization->isGranted(self::MANAGE_PERMISSION, $area),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    /**
     * SAVE THE CYCLE EDITOR'S DRAFT — the ring, the counts, the pool, the
     * rest rule, the anchor and the horizon, all in one write.
     *
     * SAVING IS NOT GENERATING. The two are separate acts and stay separate:
     * correcting a typo in a ring must not rewrite six weeks of duties on
     * the spot. The page offers "Generate from tomorrow" beside Save, and
     * that is the one that writes rows.
     */
    #[Route('/areas/{uuid}/modules/roster/rotation/{rotation}', name: self::SAVE_ROTATION_ROUTE, requirements: ['uuid' => Requirement::UUID, 'rotation' => Requirement::UUID], methods: ['POST'])]
    public function saveRotation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $rotation,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $subject = $this->rotations->findOneByUuid($area, Uuid::fromString($rotation));
        if (null === $subject) {
            throw new NotFoundHttpException('That rotation is not in this area.');
        }

        $shiftKeys = [];
        foreach ($this->shifts->openFor($area) as $shift) {
            $shiftKeys[$shift->getKey()] = $shift->getLabel();
        }

        try {
            $draft = RotationDraft::fromSubmitted(
                json_decode((string) $request->request->get('draft'), true),
                $shiftKeys,
            );
            $this->editor->apply($subject, $draft, $this->people->byUuid($area));
        } catch (\InvalidArgumentException $refused) {
            // REFUSED WHOLE AND SAID OUT LOUD. A draft is validated as one
            // object, so a malformed ring never lands half-applied — and the
            // person sees the sentence rather than a page that silently kept
            // the old ring.
            self::flash($request, 'error', $refused->getMessage());
        }

        return $this->backTo(self::ROTATION_ROUTE, $area, ['rotation' => $rotation]);
    }

    /**
     * RUN THE RING FROM TOMORROW. Today is left alone deliberately: people
     * are already standing today's watches, and regenerating the day
     * underneath them would move somebody who is at a post.
     */
    #[Route('/areas/{uuid}/modules/roster/rotation/{rotation}/generate', name: self::GENERATE_ROTATION_ROUTE, requirements: ['uuid' => Requirement::UUID, 'rotation' => Requirement::UUID], methods: ['POST'])]
    public function generateRotation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $rotation,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $subject = $this->rotations->findOneByUuid($area, Uuid::fromString($rotation));
        if (null === $subject) {
            throw new NotFoundHttpException('That rotation is not in this area.');
        }

        $from = new \DateTimeImmutable('tomorrow');
        $run = $this->generator->generate($subject, $from, $from->modify(\sprintf('+%d days', $subject->getHorizonDays())));

        self::flash($request, 'success', \sprintf(
            '%d watch%s written to %s.%s',
            $run->created,
            1 === $run->created ? '' : 'es',
            $run->through->format('j M Y'),
            [] === $run->protectedDays ? '' : \sprintf(' %d day%s somebody had edited were left alone.', $run->protectedDayCount(), 1 === $run->protectedDayCount() ? '' : 's'),
        ));

        return $this->backTo(self::ROTATION_ROUTE, $area, ['rotation' => $rotation]);
    }

    /**
     * SAVE EVERY WATCH ON THE PAGE, in one write, because the section is one
     * form with one Save. A per-row save would let somebody leave the page
     * having changed four of six and believing they changed six.
     */
    #[Route('/areas/{uuid}/modules/roster/watches', name: self::SAVE_WATCHES_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function saveWatches(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        foreach ($this->watches->forArea($area) as $watch) {
            $station = $watch->getStation();
            $id = (string) $station->getId();
            // THE RING'S ONE HOME IS THE POST. The watch's own column is
            // retired and no longer read, so the value that stands when the
            // form omits one is the post's.
            $catchment = $this->positiveInt($request, 'catchment_'.$id, $station->getCatchmentM() ?? $this->settings->forArea($area)->getDefaultCatchmentMetres());

            $this->watches->save(
                $watch,
                $this->shiftKeys($request, 'expects_'.$id),
                $this->positiveInt($request, 'silence_'.$id, $watch->getSilenceWindowMinutes()),
                $this->positiveInt($request, 'offline_'.$id, $watch->getOfflineAfterMinutes()),
            );

            // AND THE RING GOES TO ITS ONE HOME. A claim is verified
            // against the POST's catchment, so that is the only column
            // this writes: the roster states the distance and the area
            // does the measuring.
            $this->stationDesk->setCatchment($station, $catchment);
        }

        return $this->backTo(self::WATCHES_ROUTE, $area);
    }

    #[Route('/areas/{uuid}/modules/roster/settings', name: self::SAVE_SETTINGS_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function saveSettings(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $current = $this->settings->forArea($area);

        $this->settings->save(
            $area,
            $this->positiveInt($request, 'ping_interval_minutes', $current->getPingIntervalMinutes()),
            $request->request->getBoolean('off_day_has_no_state', $current->offDayHasNoState()),
            $request->request->getBoolean('leave_approval_shown', $current->isLeaveApprovalShown()),
            $this->positiveInt($request, 'default_catchment_metres', $current->getDefaultCatchmentMetres()),
            LateThreshold::tryFrom((string) $request->request->get('late_threshold')) ?? $current->getLateThreshold(),
            VacancyAnnounce::tryFrom((string) $request->request->get('vacancy_announce')) ?? $current->getVacancyAnnounce(),
        );

        return $this->backTo(self::SETTINGS_ROUTE, $area);
    }

    /**
     * THE TWO THINGS EVERY WRITE ON THIS PAGE ASKS, in one place so neither
     * can be forgotten on the next form: does this person hold the
     * permission, and did this request come from the page.
     */
    private function guardWrite(AreaOfInterest $area, Request $request): void
    {
        if (!$this->authorization->isGranted(self::MANAGE_PERMISSION, $area)) {
            throw new AccessDeniedHttpException('Changing how this area runs its roster needs the "roster.manage" permission.');
        }

        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedHttpException('That form did not come from this page.');
        }
    }

    /**
     * A NUMBER OUT OF A FORM IS UNTRUSTED. A blank, a word or a negative is
     * the value that was already stored — the entity refuses nonsense anyway,
     * and answering a fat-fingered field with a 500 helps nobody.
     */
    private function positiveInt(Request $request, string $field, int $fallback): int
    {
        $value = $request->request->getInt($field);

        return $value > 0 ? $value : $fallback;
    }

    /**
     * @return list<string>
     */
    private function shiftKeys(Request $request, string $field): array
    {
        $submitted = $request->request->all($field);

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => \is_string($key) ? $key : '', $submitted),
            static fn (string $key): bool => '' !== $key,
        ));
    }

    /**
     * @param list<StationWatch> $watches
     *
     * @return array<int, int> station id => how many people its ring draws from
     */
    private function poolSizes(array $watches): array
    {
        $pools = [];
        foreach ($watches as $watch) {
            $id = $watch->getStation()->getId();
            if (null === $id) {
                continue;
            }

            $pools[$id] = $this->watches->poolSizeFor($watch->getStation());
        }

        return $pools;
    }

    /**
     * @param list<StationWatch> $watches
     *
     * @return list<Station>
     */
    private function postsOffTheBooks(AreaOfInterest $area, array $watches): array
    {
        $onTheBooks = [];
        foreach ($watches as $watch) {
            $onTheBooks[(string) $watch->getStation()->getId()] = true;
        }

        return array_values(array_filter(
            $this->stations->findByArea($area),
            static fn (Station $station): bool => !isset($onTheBooks[(string) $station->getId()]),
        ));
    }

    /**
     * ONE OF THIS AREA'S POSTS, BY UUID — and nothing else.
     *
     * THE AREA IS PART OF THE LOOKUP, not a check after it. A uuid out of a
     * form names any post in the installation, and a write that trusted it
     * would let a form posted from one park's configure page put another
     * park's gate on these books.
     */
    private function stationIn(AreaOfInterest $area, mixed $uuid): ?Station
    {
        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        $station = $this->stations->findOneBy(['uuid' => Uuid::fromString($uuid)]);

        return $station instanceof Station && $station->getArea()?->getId() === $area->getId() ? $station : null;
    }

    /**
     * WHERE A DOOR PRESSED SOMEWHERE ELSE GOES BACK TO.
     *
     * A NAME, NEVER A URL. The Roster block on the area's Stations configure
     * card posts here too, and it says where it came from with one known
     * word — anything else in that field is the Watches section, because a
     * redirect built from a submitted address is an open redirect however
     * politely it is asked.
     */
    private function backFrom(Request $request, AreaOfInterest $area, ?Station $station): RedirectResponse
    {
        if (self::BACK_TO_THE_STATION === $request->request->get('back')) {
            return new RedirectResponse($this->router->generate(
                StationConfigureController::ROUTE,
                ['uuid' => (string) $area->getUuidString()]
                    + (null === $station ? [] : [StationConfigureController::OPEN_QUERY => (string) $station->getUuidString()]),
            ));
        }

        return $this->backTo(self::WATCHES_ROUTE, $area);
    }

    /**
     * THE POSTS A RING MAY BE DECLARED FOR — on the books, standing at least
     * one watch, and not already running one.
     *
     * @return list<Station>
     */
    private function declarablePosts(AreaOfInterest $area): array
    {
        $posts = [];
        foreach ($this->watches->forArea($area) as $watch) {
            $station = $watch->getStation();

            if (!$watch->expectsNothing() && null === $this->rotations->findOneForStation($station)) {
                $posts[] = $station;
            }
        }

        return $posts;
    }

    /**
     * @return list<Station>
     */
    private function postsWithoutARotation(AreaOfInterest $area): array
    {
        return array_values(array_filter(
            $this->stations->findByArea($area),
            fn (Station $station): bool => null === $this->rotations->findOneForStation($station),
        ));
    }

    /**
     * BACK TO THE SECTION THAT WAS SAVED, as a redirect: a POST answered with
     * a rendered page is a page a refresh re-submits.
     */
    /**
     * SAY IT IN THE FRAME'S OWN FLASHES.
     *
     * A session only carries a flash bag where the application gave it one
     * — `SessionInterface` does not promise it — so a page that assumed one
     * would 500 on an installation with a stateless session rather than
     * merely losing a sentence. The message is the lesser loss.
     */
    private static function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * @param array<string, scalar> $extra
     */
    private function backTo(string $route, AreaOfInterest $area, array $extra = []): RedirectResponse
    {
        $uuid = $area->getUuidString();

        if (null === $uuid) {
            throw new NotFoundHttpException('That area has no identifier to return to.');
        }

        return new RedirectResponse($this->router->generate($route, ['uuid' => $uuid] + $extra));
    }

    /**
     * WHICH ROTATION THE EDITOR IS ON — the one asked for, or the first.
     *
     * @param list<Rotation> $rotations
     */
    private function chosenRotation(array $rotations, mixed $asked): ?Rotation
    {
        if ([] === $rotations) {
            return null;
        }

        if (\is_string($asked) && Uuid::isValid($asked)) {
            foreach ($rotations as $rotation) {
                if ($rotation->getUuid()->toRfc4122() === $asked) {
                    return $rotation;
                }
            }
        }

        return $rotations[0];
    }

    /**
     * THE ROTATION AS THE EDITOR STARTS FROM IT. Serialised into one field
     * so the server validates the draft as one object rather than field by
     * field.
     *
     * @return array<string, mixed>
     */
    private static function draftOf(Rotation $rotation): array
    {
        return [
            'cycle' => $rotation->getCycle()->toStored(),
            'slots' => $rotation->getSlotsPerShift(),
            'pool' => array_values(array_map(
                static fn (RotationPoolMember $member): string => (string) $member->getPerson()->getUuidString(),
                $rotation->getPool()->toArray(),
            )),
            'standDown' => $rotation->getStandDownWeekdays(),
            'restRule' => $rotation->getRestRule()->value,
            'horizonDays' => $rotation->getHorizonDays(),
            'anchoredOn' => $rotation->getAnchoredOn()->format('Y-m-d'),
        ];
    }
}
