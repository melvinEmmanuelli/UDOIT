<?php

namespace App\Controller;

use App\Entity\Course;
use App\Message\BackgroundQueueItem;
use App\Message\PriorityQueueItem;
use App\MessageHandler\QueueItemHandler;
use App\Repository\CourseRepository;
use App\Response\ApiResponse;
use App\Services\UtilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class SyncController extends AbstractController
{
    protected $maxAge = '1D';

    /** @var UtilityService $util */
    protected $util;

    /**
     * @Route("/sync/{courseId}", name="request_sync")
     */
    public function requestSync(int $courseId, UtilityService $util)
    {
        $this->util = $util;

        // If Course Exists Process Scan
        $repository = $this->getDoctrine()->getRepository(Course::class);
        $course = $repository->find($courseId);
        if($course) {

            $response = new ApiResponse();
            if ($course->isActive() && !$course->isDirty()) {
                $count = $this->createApiRequests([$course], true);
                $this->addFlash('message', "Sync started on {$count} course(s)");
            }
            else {
                $this->addFlash('error', "A course scan is in progress, or the course is inactive.");
            }
        }
        else {
            $this->addFlash('error', "Course with ID {$courseId} could not be found.");
        }

        return new JsonResponse($response);
    }

    /**
     * @Route("/cron/sync", name="cron_sync")
     */
    public function cronSync(UtilityService $util)
    {
        $this->util = $util;

        /** @var CourseRepository $courseRepository */
        $courseRepository = $this->getDoctrine()->getRepository(Course::class);
        $courses = $courseRepository->findCoursesNeedingUpdate($this->maxAge);
        $count = $this->createApiRequests($courses);

        return new JsonResponse($count);
    }

    /**
     * Adds Course Refresh request to the Message Queue
     * @param $courses
     * @param bool $isPriority
     * @return int
     */
    protected function createApiRequests($courses, $isPriority = false)
    {
        // Add courses to the Messenger Queue.
        foreach ($courses as $course) {
            // Set Course to Dirty
            $course->setDirty(true);
            $this->getDoctrine()->getManager()->flush();

            if ($isPriority) {
                $this->dispatchMessage(new PriorityQueueItem($course, 'refreshContent'));
            }
            else {
                $this->dispatchMessage(new BackgroundQueueItem($course, 'refreshContent'));
            }
        }

        return count($courses);
    }
}
