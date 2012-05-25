<?php

/*
 * This file is part of the CCDN MessageBundle
 *
 * (c) CCDN (c) CodeConsortium <http://www.codeconsortium.com/> 
 * 
 * Available on github <http://www.github.com/codeconsortium/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CCDNMessage\MessageBundle\Controller;

use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * 
 * @author Reece Fowell <reece@codeconsortium.com> 
 * @version 1.0
 */
class FolderController extends ContainerAware
{

	
	/**
	 *
	 * route: /{_locale}/message/(inbox|sent|drafts|junk|trash)
	 *
	 * @access protected
	 * @param string $folder_name, int $page
	 * @return RedirectResponse|RenderResponse
	 */
	public function showFolderByNameAction($folder_name, $page)
	{	
		if ( ! $this->container->get('security.context')->isGranted('ROLE_USER'))
		{
			throw new AccessDeniedException('You do not have access to this section.');
		}
		
		if ($folder_name != 'inbox' && $folder_name != 'sent' 
		&& $folder_name != 'drafts' && $folder_name != 'junk' && $folder_name != 'trash')
		{
			throw new NotFoundHttpExceptin('Folder not found.');
		}
		
		$user = $this->container->get('security.context')->getToken()->getUser();
		
		$folders = $this->container->get('ccdn_message_message.folder.repository')->findAllFoldersForUser($user->getId());
		
		if ( ! $folders)
		{
			$this->container->get('ccdn_message_message.folder.manager')->setupDefaults($user->getId())->flushNow();

			$folders = $this->container->get('ccdn_message_message.folder.repository')->findAllFoldersForUser($user->getId());		        
		}
			
		// find the current folder	
		$currentFolder = null;
		$totalMessageCount = 0;
		foreach($folders as $key => $folder)
		{
			if ($folder->getName() == $folder_name)
			{
				$currentFolder = $folder;
			}
			$totalMessageCount += $folder->getCacheTotalMessageCount();
		}
		
		$quota = $this->container->getParameter('ccdn_message_message.quotas.max_messages');
		
		$inboxSpace = ($totalMessageCount / $quota) * 100;
		
		// where 100 represents 100%, if the number should exceed then reset it to 100%
		if ($inboxSpace > 99)
		{
			$inboxSpace = 100;
		}
		
		$messages_paginated = $this->container->get('ccdn_message_message.message.repository')->findAllPaginatedForFolderById($currentFolder, $user->getId());

		$messages_per_page = $this->container->getParameter('ccdn_message_message.folder.messages_per_page');
		$messages_paginated->setMaxPerPage($messages_per_page);
		$messages_paginated->setCurrentPage($page, false, true);
		
		$messages = $messages_paginated->getCurrentPageResults();
		
		$crumb_trail = $this->container->get('ccdn_component_crumb.trail')
			->add($this->container->get('translator')->trans('crumbs.dashboard', array(), 'CCDNForumForumBundle'), $this->container->get('router')->generate('cc_dashboard_index'), "sitemap")
			->add($this->container->get('translator')->trans('crumbs.message_index', array(), 'CCDNMessageMessageBundle'), $this->container->get('router')->generate('cc_message_index'), "home");
		
		return $this->container->get('templating')->renderResponse('CCDNMessageMessageBundle:Folder:show.html.' . $this->getEngine(), array(
			'user_profile_route' => $this->container->getParameter('ccdn_message_message.user.profile_route'),
			'crumbs' => $crumb_trail,
			'pager' => $messages_paginated,
			'folders' => $folders,
			'current_folder' => $currentFolder,
			'messages' => $messages,
			'total_message_count' => $totalMessageCount,
			'quota' => $quota,
			'inbox_space' => $inboxSpace,
		));
	}
	
	
	/**
	 *
	 * @access public
	 * @return RedirectResponse
	 */
	public function bulkAction()
	{
		if ( ! $this->container->get('security.context')->isGranted('ROLE_USER'))
		{
			throw new AccessDeniedException('You do not have access to this section.');
		}
		
		//
		// Get all the message id's.
		//
		$messageIds = array();
		$ids = $_POST;
		foreach ($ids as $messageKey => $messageId)
		{
			if (substr($messageKey, 0, 14) == 'check_message_')
			{
				//
				// Cast the key values to int upon extraction. 
				//
				$id = (int) substr($messageKey, 14, (strlen($messageKey) - 14));
				
				if (is_int($id) == true)
				{
					$messageIds[] = $id;
				}
			}
		}
		
		//
		// Don't bother if there are no messages to process.
		//
		if (count($messageIds) < 1)
		{
			return new RedirectResponse($this->container->get('router')->generate('cc_message_index'));
		}
		
		$user = $this->container->get('security.context')->getToken()->getUser();
		
		$messages = $this->container->get('ccdn_message_message.message.repository')->findTheseMessagesByUserId($messageIds, $user->getId());
		
		if ( ! $messages || empty($messages))
		{
			throw new NotFoundHttpException('No messages found!');
		}
		
		$folder = $messages[0]->getFolder();	
		
		if (isset($_POST['submit_delete']))
		{
			$folders = $this->container->get('ccdn_message_message.folder.repository')->findAllFoldersForUser($user->getId());		        

			$this->container->get('ccdn_message_message.message.manager')->bulkDelete($messages, $folders)->flushNow();
		}
		if (isset($_POST['submit_mark_as_read']))
		{
			$this->container->get('ccdn_message_message.message.manager')->bulkMarkAsRead($messages)->flushNow();
		}
		if (isset($_POST['submit_mark_as_unread']))
		{
			$this->container->get('ccdn_message_message.message.manager')->bulkMarkAsUnread($messages)->flushNow();
		}
		if (isset($_POST['submit_move_to']))
		{
			$moveTo = $this->container->get('ccdn_message_message.folder.repository')->findOneById($_POST['select_move_to']);
			$this->container->get('ccdn_message_message.message.manager')->bulkMoveToFolder($messages, $moveTo)->flushNow();
		}
		if (isset($_POST['submit_send']))
		{
			$this->container->get('ccdn_message_message.message.manager')->sendDraft($messages)->flushNow();
		}

		$this->container->get('ccdn_message_message.message.manager')->updateAllFolderCachesForUser($user);
		
		return new RedirectResponse($this->container->get('router')->generate('cc_message_folder_show', array('folder_name' => $folder->getName())));
		
//		$session = $this->container->get('request')->getSession();		
//		
//		if ($session->has('referer'))
//		{
//			return new RedirectResponse($session->get('referer'));
//		} else {
//			return new RedirectResponse($this->container->get('router')->generate('cc_message_index')); // if no referer then go to inbox
//		}
	}
	
	
	/**
	 *
	 * @access protected
	 * @return string
	 */
	protected function getEngine()
    {
        return $this->container->getParameter('ccdn_message_message.template.engine');
    }

}
