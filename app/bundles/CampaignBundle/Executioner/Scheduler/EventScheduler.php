<?php

/*
 * @copyright   2017 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CampaignBundle\Executioner\Scheduler;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\ScheduledBatchEvent;
use Mautic\CampaignBundle\Event\ScheduledEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\AbstractEventAccessor;
use Mautic\CampaignBundle\EventCollector\EventCollector;
use Mautic\CampaignBundle\Executioner\Logger\EventLogger;
use Mautic\CampaignBundle\Executioner\Scheduler\Exception\NotSchedulableException;
use Mautic\CampaignBundle\Executioner\Scheduler\Mode\DateTime;
use Mautic\CampaignBundle\Executioner\Scheduler\Mode\Interval;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class EventScheduler
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EventLogger
     */
    private $eventLogger;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var Interval
     */
    private $intervalScheduler;

    /**
     * @var DateTime
     */
    private $dateTimeScheduler;

    /**
     * @var EventCollector
     */
    private $collector;

    /**
     * @var CoreParametersHelper
     */
    private $coreParametersHelper;

    /**
     * EventScheduler constructor.
     *
     * @param LoggerInterface          $logger
     * @param EventLogger              $eventLogger
     * @param Interval                 $intervalScheduler
     * @param DateTime                 $dateTimeScheduler
     * @param EventCollector           $collector
     * @param EventDispatcherInterface $dispatcher
     */
    public function __construct(
        LoggerInterface $logger,
        EventLogger $eventLogger,
        Interval $intervalScheduler,
        DateTime $dateTimeScheduler,
        EventCollector $collector,
        EventDispatcherInterface $dispatcher,
        CoreParametersHelper $coreParametersHelper
    ) {
        $this->logger               = $logger;
        $this->dispatcher           = $dispatcher;
        $this->eventLogger          = $eventLogger;
        $this->intervalScheduler    = $intervalScheduler;
        $this->dateTimeScheduler    = $dateTimeScheduler;
        $this->collector            = $collector;
        $this->coreParametersHelper = $coreParametersHelper;
    }

    /**
     * @param Event     $event
     * @param \DateTime $executionDate
     * @param Lead      $contact
     */
    public function scheduleForContact(Event $event, \DateTime $executionDate, Lead $contact)
    {
        $contacts =  new ArrayCollection([$contact]);

        $this->schedule($event, $executionDate, $contacts);
    }

    /**
     * @param Event           $event
     * @param \DateTime       $executionDate
     * @param ArrayCollection $contacts
     * @param bool            $isInactiveEvent
     */
    public function schedule(Event $event, \DateTime $executionDate, ArrayCollection $contacts, $isInactiveEvent = false)
    {
        $config = $this->collector->getEventConfig($event);

        foreach ($contacts as $contact) {
            // Create the entry
            $log = $this->eventLogger->buildLogEntry($event, $contact, $isInactiveEvent);

            // Schedule it
            $log->setTriggerDate($executionDate);

            // Add it to the queue to persist to the DB
            $this->eventLogger->queueToPersist($log);

            //lead actively triggered this event, a decision wasn't involved, or it was system triggered and a "no" path so schedule the event to be fired at the defined time
            $this->logger->debug(
                'CAMPAIGN: '.ucfirst($event->getEventType()).' ID# '.$event->getId().' for contact ID# '.$contact->getId()
                .' has timing that is not appropriate and thus scheduled for '.$executionDate->format('Y-m-d H:m:i T')
            );

            $this->dispatchScheduledEvent($config, $log);
        }

        // Persist any pending in the queue
        $this->eventLogger->persistQueuedLogs();

        // Send out a batch event
        $this->dispatchBatchScheduledEvent($config, $event, $this->eventLogger->getLogs());

        // Update log entries and clear from memory
        $this->eventLogger->persist()
            ->clear();
    }

    /**
     * @param LeadEventLog $log
     */
    public function reschedule(LeadEventLog $log, \DateTime $toBeExecutedOn)
    {
        $log->setTriggerDate($toBeExecutedOn);
        $this->eventLogger->persistLog($log);

        $event  = $log->getEvent();
        $config = $this->collector->getEventConfig($event);

        $this->dispatchScheduledEvent($config, $log, true);
    }

    /**
     * @param LeadEventLog $log
     */
    public function rescheduleFailure(LeadEventLog $log)
    {
        if ($interval = $this->coreParametersHelper->getParameter('campaign_time_wait_on_event_false')) {
            try {
                $date = new \DateTime();
                $date->add(new \DateInterval($interval));
            } catch (\Exception $exception) {
                // Bad interval
                return;
            }

            $this->reschedule($log, $date);
        }
    }

    /**
     * @param Event          $event
     * @param \DateTime|null $compareFromDateTime
     * @param \DateTime|null $comparedToDateTime
     *
     * @return \DateTime
     *
     * @throws NotSchedulableException
     */
    public function getExecutionDateTime(Event $event, \DateTime $compareFromDateTime = null, \DateTime $comparedToDateTime = null)
    {
        if (null === $compareFromDateTime) {
            $compareFromDateTime = new \DateTime();
        } else {
            // Prevent comparisons from modifying original object
            $compareFromDateTime = clone $compareFromDateTime;
        }

        if (null === $comparedToDateTime) {
            $comparedToDateTime = clone $compareFromDateTime;
        } else {
            // Prevent comparisons from modifying original object
            $comparedToDateTime = clone $comparedToDateTime;
        }

        switch ($event->getTriggerMode()) {
            case Event::TRIGGER_MODE_IMMEDIATE:
            case null: // decision
                $this->logger->debug('CAMPAIGN: ('.$event->getId().') Executing immediately');

                return $compareFromDateTime;
            case Event::TRIGGER_MODE_INTERVAL:
                return $this->intervalScheduler->getExecutionDateTime($event, $compareFromDateTime, $comparedToDateTime);
            case Event::TRIGGER_MODE_DATE:
                return $this->dateTimeScheduler->getExecutionDateTime($event, $compareFromDateTime, $comparedToDateTime);
        }

        throw new NotSchedulableException();
    }

    /**
     * @param ArrayCollection|Event[] $events
     * @param \DateTime               $lastActiveDate
     *
     * @return array
     *
     * @throws NotSchedulableException
     */
    public function getSortedExecutionDates(ArrayCollection $events, \DateTime $lastActiveDate)
    {
        $eventExecutionDates = [];

        /** @var Event $child */
        foreach ($events as $child) {
            $eventExecutionDates[$child->getId()] = $this->getExecutionDateTime($child, $lastActiveDate);
        }

        uasort($eventExecutionDates, function (\DateTime $a, \DateTime $b) {
            if ($a === $b) {
                return 0;
            }

            return $a < $b ? -1 : 1;
        });

        return $eventExecutionDates;
    }

    /**
     * @param \DateTime $eventExecutionDate
     * @param \DateTime $earliestExecutionDate
     * @param \DateTime $now
     *
     * @return \DateTime
     */
    public function getExecutionDateForInactivity(\DateTime $eventExecutionDate, \DateTime $earliestExecutionDate, \DateTime $now)
    {
        if ($earliestExecutionDate->getTimestamp() === $eventExecutionDate->getTimestamp()) {
            // Inactivity is based on the "wait" period so execute now
            return clone $now;
        }

        return $eventExecutionDate;
    }

    /**
     * @param \DateTime $executionDate
     * @param \DateTime $now
     *
     * @return bool
     */
    public function shouldSchedule(\DateTime $executionDate, \DateTime $now)
    {
        return $executionDate > $now;
    }

    /**
     * @param AbstractEventAccessor $config
     * @param LeadEventLog          $log
     * @param bool                  $isReschedule
     */
    private function dispatchScheduledEvent(AbstractEventAccessor $config, LeadEventLog $log, $isReschedule = false)
    {
        $this->dispatcher->dispatch(
            CampaignEvents::ON_EVENT_SCHEDULED,
            new ScheduledEvent($config, $log, $isReschedule)
        );
    }

    /**
     * @param AbstractEventAccessor $config
     * @param Event                 $event
     * @param ArrayCollection       $logs
     */
    private function dispatchBatchScheduledEvent(AbstractEventAccessor $config, Event $event, ArrayCollection $logs)
    {
        $this->dispatcher->dispatch(
            CampaignEvents::ON_EVENT_SCHEDULED_BATCH,
            new ScheduledBatchEvent($config, $event, $logs)
        );
    }
}
