<?php

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Run;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Repository\EventStateRepository;
use App\Repository\UserRepository;
use DateInterval;
use DatePeriod;
use DateTime;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Contracts\Translation\TranslatorInterface;
use Intervention\Image\ImageManagerStatic as Image;


class RunEventController extends AbstractController
{
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    #[Route('/api/submit-event', name: 'submit_event', methods: ['POST'])]
    public function setSubmitted(Request $request, EventRepository $eventRepository, MailerInterface $mailer, EventStateRepository $eventStateRepository): Response
    {
        $parameters = json_decode($request->getContent(), true);
        if (!isset($parameters['isCompleted']) || !isset($parameters['eventId'])) {
            return new JsonResponse([
                'status' => 'error'
            ], 400);
        }

        $isCompleted = $parameters['isCompleted'];
        $eventId = $parameters['eventId'];

        $event = $eventRepository->findOneBy(['id' => $eventId]);

        if (!$event) {
            return new JsonResponse([
                'status' => 'error'
            ], 400);
        }

        /** @var  User */
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse([
                'status' => 'error'
            ], 401);
        }

        $entityManager = $this->getDoctrine()->getManager();
        $eventAwaitingApproval = $eventStateRepository->findOneBy(['name' => 'awaiting_approval']);
        $event->setEventState($eventAwaitingApproval);
        $entityManager->persist($event);
        $entityManager->flush();

        $emailLanguage = match ($user->getPreferredLanguage()) {
            'rus' => 'ru',
            'ukr' => 'uk',
            default => 'en-GB',
        };

        $this->translator->addLoader('json', new JsonFileLoader());

        $email = (new TemplatedEmail())
            ->from(new Address('support@everyrun.world', 'Everyrun'))
            ->to($user->getEmail())
            ->subject($this->translator->trans(
                'api_emails.event_submitted.subject',
                [],
                'messages',
                $emailLanguage
            ))
            ->htmlTemplate('email/event_submitted.html.twig')
            ->context([
                'text' => $this->translator->trans(
                    'api_emails.event_submitted.text',
                    [],
                    'messages',
                    $emailLanguage
                ),
                'dear' => $this->translator->trans(
                    'api_emails.dear',
                    [],
                    'messages',
                    $emailLanguage
                ),
                'user' => $this->getUser(),
                'ctatext' => $this->translator->trans(
                    'api_emails.event_submitted.ctatext',
                    [],
                    'messages',
                    $emailLanguage
                ),
                'buttonURL' => $this->translator->trans(
                    'api_emails.event_submitted.buttonURL',
                    [],
                    'messages',
                    $emailLanguage
                ),
                'buttonText' => $this->translator->trans(
                    'api_emails.event_submitted.buttonText',
                    [],
                    'messages',
                    $emailLanguage
                )
            ]);
        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
        }

        $email = (new TemplatedEmail())
            ->from(new Address('support@everyrun.world', 'Everyrun'))
            ->to('rodrigo@veedoo.io')
            ->addCc('bogdan@veedoo.io')
            ->addCc('alexander@veedoo.io')
            ->addCc('bogdan@districtapps.com')
            ->addCc('alona@everyrun.world')
            ->subject('🎈 New event!')
            ->htmlTemplate('email/new_event.html.twig')
            ->context([
                'user' => $this->getUser(),
                'event' => $event,
                'eventType' => $event->getEventType() ? $event->getEventType()->getName() : 'Not answered',
                'eventDescription' => $event->getDescription() ?: 'Not answered',
                'eventName' => $event->getName() ?: 'Not answered',
                'coverImage' => $event->getCoverImage() ?: null,
                'isRecurringEvent' => $event->getIsRecurrent() ?? 'Not answered',
                'country' => $event->getCountry() ?: 'Not answered',
                'city' => $event->getCity() ?: 'Not answered',
                'street' => $event->getStreet() ?: 'Not answered',
                'postalCode' => $event->getPostalCode() ?: 'Not answered',
                'houseNumber' => $event->getHouseNumber() ?: 'Not answered',
                'apartmentNumber' => $event->getApartmentNumber() ?: 'Not answered',
                'coordinates' => $event->getLng() && $event->getLat() ? $event->getLng() . ',' . $event->getLat() : 'Not answered',
                'howToFind' => $event->getHowToFindDescription() ?: 'Not answered',
                'routeLength' => $event->getRouteLength() && $event->getRouteLengthUnit() ? $event->getRouteLength() . ' ' . $event->getRouteLengthUnit()->getName() : 'Not answered',
                'minAge' => $event->getMinAge() ?: 'Not answered',
                'skillLevel' => $event->getSkillLevel() ? $event->getSkillLevel()->getName() : 'Not answered',
                'additionalRequirements' => $event->getAdditionalRequirements() ?: 'Not answered',
                'eventPrice' => $event->getPrice() ? $event->getPrice()->getName() : 'Not anwered',
                'isRegistrationRequired' => $event->getIsRegistrationRequired() ?: 'Not answered',
                'repeatsOn' => $event->getRepeatsOn() ?: 'Not anwered',
                'maxParticipants' => $event->getIsRegistrationRequired() ? $event->getMaxParticipants() : 'Not answered',
                'endsOn' => $event->getEndsOn() ?: null,
                'endsAfterOcurrences' => $event->getEndsAfterOcurrences() ?: null,
                'approveHash' => base64_encode(openssl_encrypt($event->getId(), "AES-128-ECB", 'password')),
                'rejectedHash' => base64_encode(openssl_encrypt($event->getId(), "AES-128-ECB", 'password'))
            ]);

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {

        }

        return new JsonResponse([
            'status' => 'ok'
        ]);

    }

    #[Route('/api/approve-event/{hash}', name: 'approve_event', methods: ['GET'])]
    public function setApproved(Request $request, $hash, EventRepository $eventRepository, EventStateRepository $eventStateRepository, MailerInterface $mailer): Response
    {
        if (!$hash) {
            return new JsonResponse([
                'status' => 'ko',
                'message' => 'Missing hash.'
            ]);
        }

        $eventId = openssl_decrypt(base64_decode($hash), "AES-128-ECB", 'password');
        $entityManager = $this->getDoctrine()->getManager();
        $event = $eventRepository->findOneBy(['id' => $eventId]);

        if (!$event) {
            return new JsonResponse([
                'status' => 'ko',
                'message' => 'Wrong data.'
            ]);
        }

        $eventPublishedState = $eventStateRepository->findOneBy(['name' => 'approved']);
        $event->setEventState($eventPublishedState);
        $entityManager->persist($event);
        $entityManager->flush();

        $user = $event->getUser();

        $emailLanguage = match ($user->getPreferredLanguage()) {
            'rus' => 'ru',
            'ukr' => 'uk',
            default => 'en-GB',
        };

        $email = (new TemplatedEmail())
            ->from(new Address('support@everyrun.world', 'Everyrun'))
            ->to($user->getEmail())
            ->subject($this->translator->trans(
                'api_emails.event_approved.subject',
                [],
                'messages',
                $emailLanguage
            ))
            ->htmlTemplate('email/congratulations_event_accepted.html.twig')
            ->context([
                'text' => $this->translator->trans(
                    'api_emails.event_approved.text',
                    [],
                    'messages',
                    $emailLanguage
                ),
                'dear' => $this->translator->trans(
                    'api_emails.dear',
                    [],
                    'messages',
                    $emailLanguage
                ),
                'user' => $user,
                'ctatext' => $this->translator->trans(
                    'api_emails.event_approved.ctatext',
                    [],
                    'messages',
                    $emailLanguage
                ),
                'buttonURL' => $this->translator->trans(
                    'api_emails.event_approved.buttonURL',
                    [],
                    'messages',
                    $emailLanguage
                ),
                'buttonText' => $this->translator->trans(
                    'api_emails.event_approved.buttonText',
                    [],
                    'messages',
                    $emailLanguage
                )
            ]);
        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {

        }

        return new JsonResponse([
            'status' => 'ok',
            'message' => 'The event has been approved!'
        ]);
    }

    #[Route('/api/publish-event', name: 'publish_event', methods: ['POST'])]
    public function setPublished(Request $request, EventRepository $eventRepository, EventStateRepository $eventStateRepository): Response
    {
        $parameters = json_decode($request->getContent(), true);

        if (!isset($parameters['eventId'])) {
            return new JsonResponse([
                'status' => 'error'
            ], 400);
        }

        $eventId = $parameters['eventId'];

        /** @var  User */
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse([
                'status' => 'error'
            ], 401);
        }

        $event = $eventRepository->findOneBy(['id' => $eventId, 'user' => $user]);

        if (!$event) {
            return new JsonResponse([
                'status' => 'error'
            ], 400);
        }

        if ($event->getEventState()->getName() !== "approved") {
            return new JsonResponse([
                'status' => 'error'
            ], 400);
        }

        $entityManager = $this->getDoctrine()->getManager();
        $eventPublishedState = $eventStateRepository->findOneBy(['name' => 'published']);
        $event->setEventState($eventPublishedState);
        $entityManager->persist($event);
        $entityManager->flush();

        /**
         * Generate "thumbnails" of event cover images only when published
         */
        if (!file_exists('media/event_cover_images/small')) {
            mkdir('media/event_cover_images/small', 0777, true);
        }

        if (!file_exists('media/event_cover_images/medium')) {
            mkdir('media/event_cover_images/medium', 0777, true);
        }

        if ($event->getCoverImage()) {
            $file = new File($event->getCoverImage()->getFile());
            $img = Image::make($file);
            $img->resize(540, 388, function ($constraint) {
                $constraint->aspectRatio();
            });
            $img->save('media/event_cover_images/small/' . $event->getCoverImage()->getFilePath());

            $file = new File($event->getCoverImage()->getFile());
            $img = Image::make($file);
            $img->resize(1050, 512, function ($constraint) {
                $constraint->aspectRatio();
            });
            $img->save('media/event_cover_images/medium/' . $event->getCoverImage()->getFilePath());
        }

        // Generate recurring events
        if ($event->getIsRecurrent()) {

            $dowMap = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');

            for ($i = 0; $i < count($event->getRepeatsOn()); $i++) {
                if ($event->getRepeatsOn()[$i]) {

                    $dow = $dowMap[$i];
                    $step = 1;
                    $unit = 'W';

                    $start = new DateTime($event->getDate()->format('Y-m-d H:i:s'));
                    $end = clone $start;

                    $start->modify($dow);

                    if ($event->getEndsOnOneYear()) {
                        $end->add(new DateInterval('P1Y'));
                    } else if ($event->getEndsAfterOcurrences()) {
                        $end->add(new DateInterval('P' . $event->getEndsAfterOcurrences() . 'W'));
                    } else if ($event->getEndsOn()) {
                        $end = $event->getEndsOn(); // find the last possible day before this date
                    }

                    $interval = new DateInterval("P{$step}{$unit}");
                    $period = new DatePeriod($start, $interval, $end);

                    foreach ($period as $date) {
                        $run = new Run();
                        $run->setEvent($event);
                        $run->setDate($date);
                        $run->setStartsAt($event->getStartTime());
                        $entityManager->persist($run);
                    }
                    $entityManager->flush();
                }
            }

        } else {
            $run = new Run();
            $run->setEvent($event);
            $run->setDate($event->getDate());
            $run->setStartsAt($event->getStartTime());
            $entityManager->persist($run);
            $entityManager->flush();
        }

        return new JsonResponse([
            'status' => 'ok'
        ]);

    }

    #[Route('/api/cancel-event', name: 'cancel_event', methods: ['POST'])]
    public function setCanceled(Request $request, EventRepository $eventRepository, EventStateRepository $eventStateRepository): Response
    {
        $parameters = json_decode($request->getContent(), true);

        if (!isset($parameters['eventId'])) {
            return new JsonResponse([
                'status' => 'error'
            ], 400);
        }

        /** @var  User */
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse([
                'status' => 'error'
            ], 401);
        }

        $eventId = $parameters['eventId'];

        $event = $eventRepository->findOneBy(['id' => $eventId, 'user' => $user]);

        if (!$event) {
            return new JsonResponse([
                'status' => 'error'
            ], 400);
        }

        if ($event->getEventState()->getName() !== "published") {
            return new JsonResponse([
                'status' => 'error'
            ], 400);
        }

        $entityManager = $this->getDoctrine()->getManager();
        $eventCanceledState = $eventStateRepository->findOneBy(['name' => 'canceled']);
        $event->setEventState($eventCanceledState);
        $entityManager->persist($event);
        $entityManager->flush();

        return new JsonResponse([
            'status' => 'ok'
        ]);

    }
}
