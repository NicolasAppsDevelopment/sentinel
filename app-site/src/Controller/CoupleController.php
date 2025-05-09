<?php

namespace App\Controller;

use App\Entity\Couple;
use App\Form\CoupleFormType;
use App\Service\CoupleService;
use App\Service\DetectionService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Form\FormInterface;

final class CoupleController extends AbstractController {

    public function __construct(
        private readonly CoupleService $coupleService,
        private readonly DetectionService $detectionService,
        private readonly EntityManagerInterface $entityManager,
    ) {}


    #[Route('/couples', name: 'app_couples')]
    public function index(UserInterface $user): Response
    {
        if (!$user) {
            $this->addFlash('error', 'You need to sign in to see the couples !');
            return $this->redirectToRoute('app_login');
        }

        $couples = $this->coupleService->getAllCouplesWithStatusByUser($user->getId());

        return $this->render('couple/all.html.twig', [
            'couples'=> $couples,
        ]);
    }

    #[Route('/couples/add', name: 'app_couples_add')]
    public function addCouple(Request $request, UserInterface $user): Response
    {
        if (!$user) {
            $this->addFlash('error', 'You are not authorized to add an alarm! Sign in first!');
            return $this->redirectToRoute('app_login');
        }

        $couple = new Couple();
        $form = $this->createForm(CoupleFormType::class, $couple);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            return $this->saveCoupleForm($form, $user);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        // display add page, save logic in /save !
        return $this->render('couple/add.html.twig', [
            'form' => $form,
            'errors' => $errors,
        ]);
    }

    #[Route('/couples/view/{id}', name: 'app_couples_view')]
    public function getCoupleById(string $id, PaginatorInterface $paginator, Request $request, UserInterface $user): Response
    {
        $couple = $this->coupleService->getCoupleWithStatusById($id);

        if (!$couple) {
            $this->addFlash('error', 'Alarm not found');
            return $this->redirectToRoute('app_couples');
        }

        // Check authorization
        if (!$user) {
            $this->addFlash('error', 'You need to sign in to see this alarm !');
            return $this->redirectToRoute('app_login');
        }
        if ($user->getUserIdentifier() != $couple->coupleEntity->getUser()->getUsername()) {
            $this->addFlash('error', 'You are not authorized to see this alarm !');
            return $this->redirectToRoute('app_couples');
        }

        $query = $this->detectionService->getAllDetectionsByCoupleId($id);
        $detections = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1), // current page, default 1
            15 // number of items per page
        );

        $couple->coupleEntity->setLastDetectionSeekDate(new DateTime());
        $this->entityManager->persist($couple->coupleEntity);
        $this->entityManager->flush();

        return $this->render('couple/view.html.twig', [
            'coupleInfo' => $couple,
            'detections' => $detections,
        ]);
    }

    /**
     * @param FormInterface $form
     * @param UserInterface $userInDB
     * @return RedirectResponse
     */
    public function saveCoupleForm(FormInterface $form, UserInterface $userInDB): RedirectResponse | Response
    {
        $couple = $form->getData();

        if (!$couple) {
            $this->addFlash('error', 'Alarm not found');
            return $this->redirectToRoute('app_couples');
        }

        // Check authorization
        if (!$userInDB) {
            $this->addFlash('error', 'You need to sign in to edit this alarm !');
            return $this->redirectToRoute('app_login');
        }
        if ($couple->getUser()) {
            if ($userInDB->getUserIdentifier() != $couple->getUser()->getUsername()) {
                $this->addFlash('error', 'You are not authorized to edit this alarm !');
                return $this->redirectToRoute('app_couples');
            }
        }

        $couple->setUser($userInDB);
        $couple->setAssociationDate(new DateTime());
        $couple->setEnabled(true);

        $actionDevice = $couple->getActionDevice();
        if ($actionDevice) {
            $actionDevice->setIsPaired(true);
        }

        $cameraDevice = $couple->getCameraDevice();
        if ($cameraDevice) {
            $cameraDevice->setIsPaired(true);
        }

        $this->entityManager->persist($couple);
        $this->entityManager->flush();

        $this->addFlash('success', 'Alarm saved successfully!');
        return $this->redirectToRoute('app_couples');
    }

    #[Route('/couples/enable-disable/{id}', name: 'app_couples_enable_disable')]
    public function enableDisableCouple(int $id, UserInterface $user): Response
    {

        $couple = $this->coupleService->getCoupleById($id);

        if (!$couple) {
            $this->addFlash('error', 'Alarm not found');
            return $this->redirectToRoute('app_couples');
        }

        // Check authorization
        if (!$user) {
            $this->addFlash('error', 'You need to sign in to enabled/disabled this alarm !');
            return $this->redirectToRoute('app_login');
        }
        if ($user->getUserIdentifier() != $couple->getUser()->getUsername()) {
            $this->addFlash('error', 'You are not authorized to enabled/disabled this alarm !');
            return $this->redirectToRoute('app_couples');
        }

        $couple->setEnabled(!$couple->isEnabled());
        $this->entityManager->persist($couple);
        $this->entityManager->flush();

        $this->addFlash('success', 'Alarm enabled/disabled successfully!');
        return $this->redirectToRoute('app_couples_view', array('id' => $id));
    }

    #[Route('/couples/delete/{id}', name: 'app_couples_delete')]
    public function deleteCouple(string $id, UserInterface $user): Response
    {
        $couple = $this->coupleService->getCoupleById($id);

        if (!$couple) {
            $this->addFlash('error', 'Alarm not found');
            return $this->redirectToRoute('app_couples');
        }

        // Check authorization
        if (!$user) {
            $this->addFlash('error', 'You need to sign in to delete this alarm !');
            return $this->redirectToRoute('app_login');
        }
        if ($user->getUserIdentifier() != $couple->getUser()->getUsername()) {
            $this->addFlash('error', 'You are not authorized to delete this alarm !');
            return $this->redirectToRoute('app_couples');
        }

        $this->entityManager->remove($couple);
        $this->entityManager->flush();

        $this->addFlash('success', 'Alarm deleted successfully!');
        return $this->redirectToRoute('app_couples');
    }

    #[Route('/couples/{id}/stream', name: 'app_couples_stream')]
    public function getSecureStream(string $id, UserInterface $user): Response
    {
        $couple = $this->coupleService->getCoupleById($id);

        if (!$couple) {
            $this->addFlash('error', 'Alarm not found');
            return $this->redirectToRoute('app_couples');
        }

        // Check authorization
        if (!$user) {
            $this->addFlash('error', 'You need to sign in to access this stream !');
            return $this->redirectToRoute('app_login');
        }
        if ($user->getUserIdentifier() != $couple->getUser()->getUsername()) {
            $this->addFlash('error', 'You are not authorized to access this stream !');
            return $this->redirectToRoute('app_couples');
        }

        $cameraDevice = $couple->getCameraDevice();
        if (!$cameraDevice) {
            $this->addFlash('error', 'Camera not found');
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }
        if (!$cameraDevice->isPaired()) {
            $this->addFlash('error', 'Camera not paired');
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }

        // Send internal redirect
        $streamPath = '/protected-stream/?ip=' . $cameraDevice->getIp();

        return new Response('', 200, [
            'X-Accel-Redirect' => $streamPath,
            'Content-Type' => 'multipart/x-mixed-replace; boundary=123456789000000000000987654321',
        ]);
    }

    #[Route('/couples/{id}/stream/quality/{level}', name: 'app_couples_stream_quality')]
    public function setStreamQuality(string $id, string $level, UserInterface $user): Response
    {
        $couple = $this->coupleService->getCoupleById($id);

        if (!$couple) {
            $this->addFlash('error', 'Alarm not found');
            return $this->redirectToRoute('app_couples');
        }

        // Check authorization
        if (!$user) {
            $this->addFlash('error', 'You need to sign in to set the quality of this stream !');
            return $this->redirectToRoute('app_login');
        }
        if ($user->getUserIdentifier() != $couple->getUser()->getUsername()) {
            $this->addFlash('error', 'You are not authorized to set the quality of this stream !');
            return $this->redirectToRoute('app_couples');
        }

        $cameraDevice = $couple->getCameraDevice();
        if (!$cameraDevice) {
            $this->addFlash('error', 'Camera not found');
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }
        if (!$cameraDevice->isPaired()) {
            $this->addFlash('error', 'Camera not paired');
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }

        $client = HttpClient::create();

        try {
            $url = 'http://' . $cameraDevice->getIp() . '/control?var=framesize&val=' . $level;
            $response = $client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                $this->addFlash('error', 'Unable to set stream quality: ' . $response->getStatusCode());
                return $this->redirectToRoute('app_couples_view', array('id' => $id));
            }

            return new StreamedResponse(function () use ($response) {
                echo $response->getContent();
            }, 200);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Unable set stream quality: ' . $e->getMessage());
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }
    }

    #[Route('/couples/{id}/capture', name: 'app_couples_capture', methods: 'GET')]
    public function getSecureCapture(string $id, UserInterface $user): Response
    {
        $couple = $this->coupleService->getCoupleById($id);

        if (!$couple) {
            $this->addFlash('error', 'Alarm not found');
            return $this->redirectToRoute('app_couples');
        }

        // Check authorization
        if (!$user) {
            $this->addFlash('error', 'You need to sign in to take a capture !');
            return $this->redirectToRoute('app_login');
        }
        if ($user->getUserIdentifier() != $couple->getUser()->getUsername()) {
            $this->addFlash('error', 'You are not authorized to take a capture !');
            return $this->redirectToRoute('app_couples');
        }

        $cameraDevice = $couple->getCameraDevice();
        if ($cameraDevice === null) {
            $this->addFlash('error', 'Camera not found');
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }
        if ($cameraDevice->isPaired() === false) {
            $this->addFlash('error', 'Camera not paired');
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }

        $client = HttpClient::create();

        try {
            $url = 'http://' . $cameraDevice->getIp() . '/capture?_cb=1744097322029';
            $response = $client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                $this->addFlash('error', 'Unable to capture image: ' . $response->getStatusCode());
                return $this->redirectToRoute('app_couples_view', array('id' => $id));
            }

            $contentType = $response->getHeaders()['content-type'][0] ?? 'image/jpg';

            return new StreamedResponse(function () use ($response) {
                echo $response->getContent();
            }, 200, [
                'Content-Type' => $contentType,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Unable fetching image: ' . $e->getMessage());
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }
    }

    #[Route('/couples/{id}/update', name: 'app_couple_update_name', methods: ['POST'])]
    public function update(Request $request, int $id, UserInterface $user): RedirectResponse | Response
    {
        $couple = $this->coupleService->getCoupleById($id);

        if (!$couple) {
            $this->addFlash('error', 'Alarm not found');
            return $this->redirectToRoute('app_couples');
        }

        // Check authorization
        if (!$user) {
            $this->addFlash('error', 'You need to sign in to update this alarm !');
            return $this->redirectToRoute('app_login');
        }
        if ($user->getUserIdentifier() != $couple->getUser()->getUsername()) {
            $this->addFlash('error', 'You are not authorized to update this alarm !');
            return $this->redirectToRoute('app_couples');
        }

        $newTitle = $request->request->get('title', '');

        $couple->setTitle($newTitle);

        $this->entityManager->persist($couple);
        $this->entityManager->flush();


        $this->addFlash('success', 'Alarm updated successfully!');
        return $this->redirectToRoute('app_couples_view', array('id' => $id));
    }

    #[Route('/couples/{id}/enable-disable-buzzer', name: 'app_couples_enable_disable_buzzer')]
    public function enableDisableBuzzer(string $id, UserInterface $user): Response
    {
        $couple = $this->coupleService->getCoupleWithStatusById($id);

        if (!$couple) {
            $this->addFlash('error', 'Alarm not found !');
            return $this->redirectToRoute('app_couples');
        }

        // Check authorization
        if (!$user) {
            $this->addFlash('error', 'You need to sign in to enabled/disabled this buzzer !');
            return $this->redirectToRoute('app_login');
        }
        if ($user->getUserIdentifier() != $couple->coupleEntity->getUser()->getUsername()) {
            $this->addFlash('error', 'You are not authorized to enabled/disabled this buzzer !');
            return $this->redirectToRoute('app_couples');
        }

        $actionDevice = $couple->coupleEntity->getActionDevice();
        if ($actionDevice === null) {
            $this->addFlash('error', 'Action module not found');
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }
        if ($actionDevice->isPaired() === false) {
            $this->addFlash('error', 'Action module not paired');
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }

        $client = HttpClient::create();

        try {
            $url = 'http://' . $actionDevice->getIp() . '/' . ($couple->actionStatus->buzzerEnabled ? 'disable' : 'enable') . '_buzzer';
            $response = $client->request('GET', $url);

            if ($response->getStatusCode() !== 200) {
                throw new Exception('Bad status code response: ' . $response->getStatusCode());
            }
        } catch (Exception $e) {
            $this->addFlash('error', 'Unable to enabled/disabled buzzer: ' . $e->getMessage());
            return $this->redirectToRoute('app_couples_view', array('id' => $id));
        }

        $this->addFlash('success', 'Buzzer enabled/disabled successfully !');
        return $this->redirectToRoute('app_couples_view', array('id' => $id));
    }
}
