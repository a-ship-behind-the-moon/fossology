<?php
/*
Copyright (C) 2014, Siemens AG

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

namespace Fossology\Lib\BusinessRules;


use Fossology\Lib\Dao\AgentsDao;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Data\LicenseDecision\AgentLicenseDecisionEvent;
use Fossology\Lib\Data\LicenseDecision\LicenseDecisionEvent;
use Fossology\Lib\Data\LicenseDecision\LicenseDecisionEventBuilder;
use Fossology\Lib\Data\LicenseDecision\LicenseDecisionResult;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Data\DecisionTypes;

class ClearingDecisionEventProcessor
{
  /** @var LicenseDao */
  private $licenseDao;

  /** @var AgentsDao */
  private $agentsDao;

  /** @var ClearingDao */
  private $clearingDao;

  public function __construct($licenseDao, $agentsDao, $clearingDao)
  {
    $this->licenseDao = $licenseDao;
    $this->agentsDao = $agentsDao;
    $this->clearingDao = $clearingDao;
  }

  /**
   * @param ItemTreeBounds $itemTreeBounds
   * @param int
   * @return array
   */
  protected function getLatestAgentDetectedLicenses(ItemTreeBounds $itemTreeBounds,$uploadId)
  {
    $agentDetectedLicenses = array();
    $agentsWithResults = array();
    $licenseFileMatches = $this->licenseDao->getAgentFileLicenseMatches($itemTreeBounds);
    foreach ($licenseFileMatches as $licenseMatch)
    {
      $licenseRef = $licenseMatch->getLicenseRef();
      $licenseShortName = $licenseRef->getShortName();
      if ($licenseShortName === "No_license_found")
      {
        continue;
      }
      $agentRef = $licenseMatch->getAgentRef();
      $agentName = $agentRef->getAgentName();
      $agentId = $agentRef->getAgentId();
      $agentsWithResults[$agentName] = $agentName;
      //$agentDetectedLicenses[$licenseShortName][$agentName][$agentId][] = array(
      $agentDetectedLicenses[$agentName][$agentId][$licenseShortName][] = array(
          'id' => $licenseRef->getId(),
          'licenseRef' => $licenseRef,
          'agentRef' => $agentRef,
          'matchId' => $licenseMatch->getLicenseFileId(),
          'percentage' => $licenseMatch->getPercentage()
      );
    }
    $agentLatestMap = $this->agentsDao->getLatestAgentResultForUpload($uploadId, $agentsWithResults);
    $latestAgentDetectedLicenses = $this->functionWithNoName($agentDetectedLicenses,$agentLatestMap);
    return $latestAgentDetectedLicenses;
  }
  
  /**
   * (A->B->C->X, A->B) => C->A->X
   * @param array[][][]
   * @param array $agentLatestMap
   * @return array[][]
   */
  protected function filterDetectedLicenses($agentDetectedLicenses,$agentLatestMap){
    $latestAgentDetectedLicenses = array();
    foreach($agentDetectedLicenses as $agentName=>$namedAgentDetectedLicenses)
    {
      if (!array_key_exists($agentName, $agentLatestMap))
      {
        continue;
      }
      $latestAgentId = $agentLatestMap[$agentName];
      if (!array_key_exists($latestAgentId,$namedAgentDetectedLicenses))
      {
        continue;
      }
      foreach($namedAgentDetectedLicenses[$latestAgentId] as $licenseShortName=>$properties)
      {
        $latestAgentDetectedLicenses[$licenseShortName][$agentName] = $properties;
      }
    }
    return $latestAgentDetectedLicenses;
  }


  /**
   * @param ItemTreeBounds $itemTreeBounds
   * @param int $userId
   * @return array
   */
  public function getCurrentLicenseDecisions(ItemTreeBounds $itemTreeBounds, $userId)
  {
    $uploadTreeId = $itemTreeBounds->getUploadTreeId();
    $uploadId = $itemTreeBounds->getUploadId();

    $agentDetectedLicenses = $this->getLatestAgentDetectedLicenses($itemTreeBounds, $uploadId);

    list($addedLicenses, $removedLicenses) = $this->clearingDao->getCurrentLicenseDecisions($userId, $uploadTreeId);

    $currentLicenses = array_unique(array_merge(array_keys($addedLicenses), array_keys($agentDetectedLicenses)));

    $licenseDecisions = array();
    $removed = array();
    foreach ($currentLicenses as $licenseShortName)
    {
      $licenseDecisionEvent = null;
      $agentLicenseDecisionEvents = array();

      if (array_key_exists($licenseShortName, $addedLicenses))
      {
        /** @var LicenseDecisionEvent $addedLicense */
        $addedLicense = $addedLicenses[$licenseShortName];
        $licenseDecisionEvent = $addedLicense;
      }

      if (array_key_exists($licenseShortName, $agentDetectedLicenses))
      {
        foreach ($agentDetectedLicenses[$licenseShortName] as $agentName => $licenseProperty)
        {
          foreach ($licenseProperties as $licenseProperty)
          {
            $agentLicenseDecisionEvents[] = new AgentLicenseDecisionEvent(
                $licenseProperty['licenseRef'],
                $licenseProperty['agentRef'],
                $licenseProperty['matchId'],
                array_key_exists('percentage', $licenseProperty) ? $licenseProperty['percentage'] : null
            );
          }
        }
      }

      if (($licenseDecisionEvent !== null) || (count($agentLicenseDecisionEvents) > 0))
      {
        $licenseDecisionResult = new LicenseDecisionResult($licenseDecisionEvent, $agentLicenseDecisionEvents);

        if (array_key_exists($licenseShortName, $removedLicenses))
        {
          $removed[$licenseShortName] = $licenseDecisionResult;
        } else
        {
          $licenseDecisions[$licenseShortName] = $licenseDecisionResult;
        }
      }
    }

    return array($licenseDecisions, $removed);
  }

  public function makeDecisionFromLastEvents(ItemTreeBounds $itemBounds, $userId, $type, $isGlobal)
  {
    $item = $itemBounds->getUploadTreeId();
    if ($type <= 1)
    {
      return;
    }
    $events = $this->clearingDao->getRelevantLicenseDecisionEvents($userId, $item);
    $clearingDecision = $this->clearingDao->getRelevantClearingDecision($userId, $item);

    list($added, $removed) = $this->getCurrentLicenseDecisions($itemBounds, $userId);

    $lastDecision = null;
    if ($clearingDecision)
    {
      $lastDecision = $clearingDecision['date_added'];
    }

    $insertDecision = false;
    foreach (array_merge($added, $removed) as $licenseShortName => $licenseDecisionResult)
    {
      /** @var LicenseDecisionResult $licenseDecisionResult */
      if (!$licenseDecisionResult->hasLicenseDecisionEvent())
      {
        $insertDecision = true;
        break;
      }

      $entryTimestamp = $licenseDecisionResult->getLicenseDecisionEvent()->getDateTime();
      if ($lastDecision === null || $lastDecision < $entryTimestamp)
      {
        $insertDecision = true;
        break;
      }
    }

    $removedSinceLastDecision = array();
    foreach ($events as $event)
    {
      $licenseShortName = $event->getLicenseShortName();
      $entryTimestamp = $event->getDateTime();
      if ($event->isRemoved() && !array_key_exists($licenseShortName, $added) && $lastDecision < $entryTimestamp)
      {
        $removedSinceLastDecision[$licenseShortName] = $event;
        $insertDecision = true;
      }
    }

    // handle "No license known"
    if ($type === 2)
    {
      $insertDecision = true;
      $removedSinceLastDecision = array();
      $licenseDecisionEventBuilder = new LicenseDecisionEventBuilder();
      foreach($added as $licenseShortName => $licenseDecisionResult) {
        /** @var LicenseDecisionResult $licenseDecisionResult */
        $isglobal =$licenseDecisionResult->hasLicenseDecisionEvent()? $licenseDecisionResult->getLicenseDecisionEvent()->isGlobal():true;
        $this->clearingDao->removeLicenseDecision($itemBounds->getUploadTreeId(), $userId,
            $licenseDecisionResult->getLicenseId(), $type, $isglobal);
        $licenseDecisionEventBuilder
            ->setLicenseRef($licenseDecisionResult->getLicenseRef());
        //we only need the license ID so the builder defaults should suffice for the rest
        $removedSinceLastDecision[$licenseShortName] = $licenseDecisionEventBuilder->build();
      }

      $added = array();
      $type = DecisionTypes::IDENTIFIED;
    }

    if ($insertDecision)
    {
      $this->clearingDao->insertClearingDecision($item, $userId, $type, $isGlobal, $added, $removedSinceLastDecision);
    }
  }

}