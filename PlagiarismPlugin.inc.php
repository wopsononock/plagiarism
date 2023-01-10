<?php

/**
 * @file PlagiarismPlugin.inc.php
 *
 * Copyright (c) 2003-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Plagiarism plugin
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('classes.notification.NotificationManager');

class PluginNotificationManager extends NotificationManager {
		public function getNotificationUrl($request, $notification) {
		$url = parent::getNotificationUrl($request, $notification);
		$dispatcher = Application::get()->getDispatcher();
		$contextDao = Application::getContextDAO();
		$context = $contextDao->getById($notification->getContextId());

		switch ($notification->getType()) {
			case NOTIFICATION_TYPE_EDITOR_ASSIGN:
				assert($notification->getAssocType() == ASSOC_TYPE_SUBMISSION && is_numeric($notification->getAssocId()));
				return $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'workflow', 'access', $notification->getAssocId());
			case NOTIFICATION_TYPE_COPYEDIT_ASSIGNMENT:
			case NOTIFICATION_TYPE_LAYOUT_ASSIGNMENT:
			case NOTIFICATION_TYPE_INDEX_ASSIGNMENT:
				assert($notification->getAssocType() == ASSOC_TYPE_SUBMISSION && is_numeric($notification->getAssocId()));
				return $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'workflow', 'access', $notification->getAssocId());
			case NOTIFICATION_TYPE_REVIEWER_COMMENT:
				assert($notification->getAssocType() == ASSOC_TYPE_REVIEW_ASSIGNMENT && is_numeric($notification->getAssocId()));
				$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao ReviewAssignmentDAO */
				$reviewAssignment = $reviewAssignmentDao->getById($notification->getAssocId());
				$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
				$operation = $reviewAssignment->getStageId()==WORKFLOW_STAGE_ID_INTERNAL_REVIEW?WORKFLOW_STAGE_PATH_INTERNAL_REVIEW:WORKFLOW_STAGE_PATH_EXTERNAL_REVIEW;
				return $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'workflow', $operation, $reviewAssignment->getSubmissionId());
			case NOTIFICATION_TYPE_REVIEW_ASSIGNMENT:
			case NOTIFICATION_TYPE_REVIEW_ASSIGNMENT_UPDATED:
				$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao ReviewAssignmentDAO */
				$reviewAssignment = $reviewAssignmentDao->getById($notification->getAssocId());
				return $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'reviewer', 'submission', $reviewAssignment->getSubmissionId());
			case NOTIFICATION_TYPE_NEW_ANNOUNCEMENT:
				assert($notification->getAssocType() == ASSOC_TYPE_ANNOUNCEMENT);
				$announcementDao = DAORegistry::getDAO('AnnouncementDAO'); /* @var $announcementDao AnnouncementDAO */
				$announcement = $announcementDao->getById($notification->getAssocId()); /* @var $announcement Announcement */
				$context = $contextDao->getById($announcement->getAssocId());
				return $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'announcement', 'view', array($notification->getAssocId()));
			case NOTIFICATION_TYPE_CONFIGURE_PAYMENT_METHOD:
				return __('notification.type.configurePaymentMethod');
			case NOTIFICATION_TYPE_CONFIGURE_PLUGIN:
				return $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'announcement');
			case NOTIFICATION_TYPE_PAYMENT_REQUIRED:
				$context = $contextDao->getById($notification->getContextId());
				Application::getPaymentManager($context);
				assert($notification->getAssocType() == ASSOC_TYPE_QUEUED_PAYMENT);
				$queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO'); /* @var $queuedPaymentDao QueuedPaymentDAO */
				$queuedPayment = $queuedPaymentDao->getById($notification->getAssocId());
				$context = $contextDao->getById($queuedPayment->getContextId());
				return $dispatcher->url($request, ROUTE_PAGE, $context->getPath(), 'payment', 'pay', array($queuedPayment->getId()));
			default:
				$delegateResult = $this->getByDelegate(
					$notification->getType(),
					$notification->getAssocType(),
					$notification->getAssocId(),
					__FUNCTION__,
					array($request, $notification)
				);

				if ($delegateResult) $url = $delegateResult;

				return $url;
		}
		}
}
class PlagiarismPlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();

		if ($success && $this->getEnabled()) {
			HookRegistry::register('submissionsubmitstep4form::execute', array($this, 'callback'));
		}
		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.plagiarism.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.plagiarism.description');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanEnable()
	 */
	function getCanEnable($contextId = null) {
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanDisable()
	 */
	function getCanDisable($contextId = null) {
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getEnabled()
	 */
	function getEnabled($contextId = null) {
		return parent::getEnabled($contextId) || Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * Fetch credentials from config.inc.php, if available
	 * @return array username and password, or null(s)
	**/
	function getForcedCredentials() {
		$request = Application::getRequest();
		$context = $request->getContext();
		$contextPath = $context->getPath();
		$username = Config::getVar('ithenticate', 'username[' . $contextPath . ']',
				Config::getVar('ithenticate', 'username'));
		$password = Config::getVar('ithenticate', 'password[' . $contextPath . ']',
				Config::getVar('ithenticate', 'password'));
		return [$username, $password];
	}

	/**
	 * Send the editor an error message
	 * @param $submissionid int
	 * @param $message string
	 * @return void
	**/
	public function sendErrorMessage($submissionid, $message) {
		$request = Application::getRequest();
		$context = $request->getContext();
		import('classes.notification.NotificationManager');
		$notificationManager = new PluginNotificationManager();
		$roleDao = DAORegistry::getDAO('RoleDAO'); /* @var $roleDao RoleDAO */
		// Get the managers.
		$managers = $roleDao->getUsersByRoleId(ROLE_ID_MANAGER, $context->getId());
		while ($manager = $managers->next()) {
			$notificationManager->createTrivialNotification($manager->getId(), NOTIFICATION_TYPE_ERROR, array('contents' => __('plugins.generic.plagiarism.errorMessage', array('submissionId' => $submissionid, 'errorMessage' => $message))));
		}
		//1 is admin. This can move inside the loop to notify all journal managers when finished.
		$notificationManager->createNotification($request,1, NOTIFICATION_TYPE_CONFIGURE_PLUGIN, $context->getId(), null ,null,NOTIFICATION_LEVEL_TASK, array('contents' => __('plugins.generic.plagiarism.errorMessage', array('submissionId' => $submissionid, 'errorMessage' => $message))));
		error_log('iThenticate submission '.$submissionid.' failed: '.$message);
	}
	
	/**
	 * Send submission files to iThenticate.
	 * @param $hookName string
	 * @param $args array
	 */
	public function callback($hookName, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$contextPath = $context->getPath();
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
		$submission = $submissionDao->getById($request->getUserVar('submissionId'));
		$publication = $submission->getCurrentPublication();

		require_once(dirname(__FILE__) . '/vendor/autoload.php');

		// try to get credentials for current context otherwise use default config
        	$contextId = $context->getId();
		list($username, $password) = $this->getForcedCredentials(); 
		if (empty($username) || empty($password)) {
			$username = $this->getSetting($contextId, 'ithenticateUser');
			$password = $this->getSetting($contextId, 'ithenticatePass');
			//the Ithenticate class has a bug that prevents it from gracefully handling how the API responds to a blank username or password
			//Do not send the request if the creds are missing. Do generate an error message to tell the manager to configure the plugin.
			if ($username===null || $password===null) {
				$this->sendErrorMessage($submission->getId(), "Check that the iThenticate username/password are set in plugin settings");
				return false;
			}
		}

		$ithenticate = null;
		try {
			$ithenticate = new \bsobbe\ithenticate\Ithenticate($username, $password);
		} catch (Exception $e) {
			$this->sendErrorMessage($submission->getId(), $e->getMessage());
			return false;
		}
		// Make sure there's a group list for this context, creating if necessary.
		$groupList = $ithenticate->fetchGroupList();
		$contextName = $context->getLocalizedName($context->getPrimaryLocale());
		if (!($groupId = array_search($contextName, $groupList))) {
			// No folder group found for the context; create one.
			$groupId = $ithenticate->createGroup($contextName);
			if (!$groupId) {
				$this->sendErrorMessage($submission->getId(), 'Could not create folder group for context ' . $contextName . ' on iThenticate.');
				return false;
			}
		}

		// Create a folder for this submission.
		if (!($folderId = $ithenticate->createFolder(
			'Submission_' . $submission->getId(),
			'Submission_' . $submission->getId() . ': ' . $publication->getLocalizedTitle($publication->getData('locale')),
			$groupId,
			true,
			true
		))) {
			$this->sendErrorMessage($submission->getId(), 'Could not create folder for submission ID ' . $submission->getId() . ' on iThenticate.');
			return false;
		}

		$submissionFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$submission->getId()],
		]);

		$authors = $publication->getData('authors');
		$author = array_shift($authors);
		foreach ($submissionFiles as $submissionFile) {
			$file = Services::get('file')->get($submissionFile->getData('fileId'));
			if (!$ithenticate->submitDocument(
				$submissionFile->getLocalizedData('name'),
				$author->getLocalizedGivenName(),
				$author->getLocalizedFamilyName(),
				$submissionFile->getLocalizedData('name'),
				Services::get('file')->fs->read($file->path),
				$folderId
			)) {
				$this->sendErrorMessage($submission->getId(), 'Could not submit "' . $submissionFile->getData('path') . '" to iThenticate.');
			}
		}

		return false;
	}
	
	/**
     * @copydoc Plugin::getActions()
     */
    function getActions($request, $verb) {
        $router = $request->getRouter();
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        return array_merge(
                $this->getEnabled() ? array(
            new LinkAction(
                    'settings',
                    new AjaxModal(
                            $router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
                            $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
            ),
                ) : array(),
                parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    function manage($args, $request) {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext();

                AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->registerPlugin('function', 'plugin_url', array($this, 'smartyPluginUrl'));

                $this->import('PlagiarismSettingsForm');
                $form = new PlagiarismSettingsForm($this, $context->getId());

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }
}

/**
 * Low-budget mock class for \bsobbe\ithenticate\Ithenticate -- Replace the
 * constructor above with this class name to log API usage instead of
 * interacting with the iThenticate service.
 */
class TestIthenticate {
	public function __construct($username, $password) {
		error_log("Constructing iThenticate: $username $password");
	}

	public function fetchGroupList() {
		error_log('Fetching iThenticate group list');
		return array();
	}

	public function createGroup($group_name) {
		error_log("Creating group named \"$group_name\"");
		return 1;
	}

	public function createFolder($folder_name, $folder_description, $group_id, $exclude_quotes) {
		error_log("Creating folder:\n\t$folder_name\n\t$folder_description\n\t$group_id\n\t$exclude_quotes");
		return true;
	}

	public function submitDocument($essay_title, $author_firstname, $author_lastname, $filename, $document_content, $folder_number) {
		error_log("Submitting document:\n\t$essay_title\n\t$author_firstname\n\t$author_lastname\n\t$filename\n\t" . strlen($document_content) . " bytes of content\n\t$folder_number");
		return true;
	}
}
