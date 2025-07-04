<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */

/**
 * @class  boardView
 * @author NAVER (developers@xpressengine.com)
 * @brief  board module View class
 */
class BoardView extends Board
{
	/**
	 * Default values
	 */
	public $listConfig = [];
	public $columnList = [];

	/**
	 * @brief initialization
	 */
	public function init()
	{
		$oSecurity = new Security();
		$oSecurity->encodeHTML('document_srl', 'comment_srl', 'vid', 'mid', 'page', 'category', 'search_target', 'search_keyword', 'sort_index', 'order_type', 'trackback_srl');

		/**
		 * setup the module general information
		 */
		$m = Context::get('m');
		$this->list_count = $m ? ($this->module_info->mobile_list_count ?? 20) : ($this->module_info->list_count ?? 20);
		$this->search_list_count = $m ? ($this->module_info->mobile_search_list_count ?? 20) : ($this->module_info->search_list_count ?? 20);
		$this->page_count = $m ? ($this->module_info->mobile_page_count ?? 5) : ($this->module_info->page_count ?? 10);
		$this->except_notice = ($this->module_info->except_notice ?? '') == 'N' ? FALSE : TRUE;
		$this->include_modules = ($this->module_info->include_modules ?? []) ? explode(',', $this->module_info->include_modules) : [];
		if (count($this->include_modules) && !in_array($this->module_info->module_srl, $this->include_modules))
		{
			$this->include_modules[] = $this->module_info->module_srl;
		}

		// $this->_getStatusNameListecret option backward compatibility
		$statusList = $this->_getStatusNameList();
		if(isset($statusList['SECRET']))
		{
			$this->module_info->secret = 'Y';
		}

		// use_category <=1.5.x, hide_category >=1.7.x
		$count_category = count(DocumentModel::getCategoryList($this->module_info->module_srl));
		if($count_category)
		{
			if(isset($this->module_info->hide_category))
			{
				$this->module_info->use_category = ($this->module_info->hide_category == 'Y') ? 'N' : 'Y';
			}
			elseif(isset($this->module_info->use_category))
			{
				$this->module_info->hide_category = ($this->module_info->use_category == 'Y') ? 'N' : 'Y';
			}
			else
			{
				$this->module_info->hide_category = 'N';
				$this->module_info->use_category = 'Y';
			}
		}
		else
		{
			$this->module_info->hide_category = 'Y';
			$this->module_info->use_category = 'N';
		}

		/**
		 * check the consultation function, if the user is admin then swich off consultation function
		 * if the user is not logged, then disppear write document/write comment./ view document
		 */
		if($this->module_info->consultation == 'Y' && !$this->grant->manager && !$this->grant->consultation_read)
		{
			$this->consultation = TRUE;
			if(!Context::get('is_logged'))
			{
				$this->grant->list = FALSE;
				$this->grant->write_document = FALSE;
				$this->grant->write_comment = FALSE;
				$this->grant->view = FALSE;
			}
		}
		else
		{
			$this->consultation = FALSE;
		}

		/**
		 * use context::set to setup extra variables
		 */
		$extra_keys = DocumentModel::getExtraKeys($this->module_info->module_srl);
		Context::set('extra_keys', $extra_keys);

		/**
		 * add extra variables to order(sorting) target
		 */
		if (is_array($extra_keys))
		{
			foreach($extra_keys as $val)
			{
				$this->order_target[] = $val->eid;
			}
		}
		/**
		 * load javascript, JS filters
		 */
		Context::addJsFilter($this->module_path.'tpl/filter', 'input_password.xml');
		Context::loadFile([$this->module_path.'tpl/js/board.js', 'head']);
		if (config('url.rewrite') > 1)
		{
			Context::loadFile([$this->module_path.'tpl/js/rewrite.js', 'body']);
		}
		Context::loadLang('./modules/document/lang');
		Context::loadLang('./modules/comment/lang');

		// remove [document_srl]_cpage from get_vars
		$args = Context::getRequestVars();
		foreach($args as $name => $value)
		{
			if(preg_match('/[0-9]+_cpage/', $name))
			{
				Context::set($name, '', TRUE);
				Context::set($name, $value);
			}
		}
	}

	/**
	 * @brief display board contents
	 */
	public function dispBoardContent()
	{
		/**
		 * check the access grant (all the grant has been set by the module object)
		 */
		if(!$this->grant->access || !$this->grant->list)
		{
			$this->dispBoardMessage($this->user->isMember() ? 'msg_not_permitted' : 'msg_not_logged');
		}

		/**
		 * display the category list, and then setup the category list on context
		 */
		$this->dispBoardCategoryList();

		/**
		 * display the search options on the screen
		 * add extra vaiables to the search options
		 */
		// use search options on the template (the search options key has been declared, based on the language selected)
		foreach($this->search_option as $opt) $search_option[$opt] = lang($opt);
		$extra_keys = Context::get('extra_keys');
		if($extra_keys)
		{
			foreach($extra_keys as $key => $val)
			{
				if($val->search == 'Y') $search_option['extra_vars'.$val->idx] = $val->name;
			}
		}
		// remove a search option that is not public in member config
		$memberConfig = ModuleModel::getModuleConfig('member');
		foreach($memberConfig->signupForm as $signupFormElement)
		{
			if(in_array($signupFormElement->title, $search_option))
			{
				if($signupFormElement->isPublic == 'N')
				{
					unset($search_option[$signupFormElement->name]);
				}
			}
		}
		Context::set('search_option', $search_option);

		$statusNameList = $this->_getStatusNameList();
		if(count($statusNameList) > 0)
		{
			Context::set('status_list', $statusNameList);
		}

		// display the board content
		$output = $this->dispBoardContentView();
		if ($output instanceof DocumentItem)
		{
			$this->setRedirectUrl($output->getPermanentUrl());
			return;
		}
		if ($this->getHttpStatusCode() > 200)
		{
			return;
		}

		// list config, columnList setting
		$this->listConfig = BoardModel::getListConfig($this->module_info->module_srl);
		if(!$this->listConfig) $this->listConfig = array();
		$this->_makeListColumnList();

		// display the notice list
		$this->dispBoardNoticeList();

		// list
		$this->dispBoardContentList();

		// Board features
		$oDocument = Context::get('oDocument');
		$document_module_srl = ($oDocument && $oDocument->isExists()) ? $oDocument->get('module_srl') : $this->module_srl;
		$board_features = Rhymix\Modules\Board\Models\Features::fromModuleInfo($this->module_info, $document_module_srl);
		Context::set('board_features', $board_features);

		/**
		 * add javascript filters
		 */
		Context::addJsFilter($this->module_path.'tpl/filter', 'search.xml');

		$oSecurity = new Security();
		$oSecurity->encodeHTML('search_option.');

		// setup the tmeplate file
		$this->setTemplateFile('list');
	}

	/**
	 * @brief display the category list
	 */
	public function dispBoardCategoryList()
	{
		// check if the use_category option is enabled
		if ($this->module_info->use_category === 'Y' || !empty($this->include_modules))
		{
			// check the grant
			if(!$this->grant->list)
			{
				Context::set('category_list', array());
				return;
			}

			// Get category list for documents belong to other modules. (i.e. submodule in combined board)
			if (empty($this->include_modules))
			{
				$category_list = DocumentModel::getCategoryList($this->module_srl);
			}
			else
			{
				if ($this->module_info->use_category === 'Y')
				{
					$category_list = DocumentModel::getCategoryList($this->module_srl);
				}
				else
				{
					$category_list = [];
				}

				foreach ($this->include_modules as $module_srl)
				{
					if ($module_srl != $this->module_srl)
					{
						if ((ModuleModel::getModuleExtraVars($module_srl)->hide_category ?? 'N') !== 'Y')
						{
							$category_list += DocumentModel::getCategoryList($module_srl);
						}
					}
				}

				if ($category_list)
				{
					$this->module_info->hide_category = 'N';
					$this->module_info->use_category = 'Y';
				}
			}
			Context::set('category_list', $category_list);

			$oSecurity = new Security();
			$oSecurity->encodeHTML('category_list.', 'category_list.childs.');
		}
	}

	/**
	 * @brief display the board conent view
	 */
	public function dispBoardContentView()
	{
		// get the variable value
		$document_srl = (int)Context::get('document_srl');

		/**
		 * if the document exists, then get the document information
		 */
		if($document_srl)
		{
			$oDocument = DocumentModel::getDocument($document_srl, false, true);

			// if the document is existed
			if($oDocument->isExists())
			{
				// if the module srl is not consistent
				if($oDocument->get('module_srl') != $this->module_info->module_srl && $oDocument->get('is_notice') !== 'A')
				{
					if (!in_array($oDocument->get('module_srl'), $this->include_modules))
					{
						return $oDocument;
					}
					if (isset($this->module_info->include_days) && $this->module_info->include_days > 0)
					{
						if ($oDocument->get('regdate') < date('YmdHis', time() - ($this->module_info->include_days * 86400)))
						{
							return $oDocument;
						}
					}
				}

				// if the consultation function is enabled, and the document is not a notice
				if($this->consultation && !$oDocument->isNotice())
				{
					$logged_info = Context::get('logged_info');
					if(abs($oDocument->get('member_srl')) != $logged_info->member_srl)
					{
						$oDocument = DocumentModel::getDocument(0);
					}
				}

				// if the document is TEMP saved, check Grant
				if($oDocument->getStatus() == 'TEMP')
				{
					if(!$oDocument->isGranted())
					{
						$oDocument = DocumentModel::getDocument(0);
					}
				}

			}
			else
			{
				// if the document is not existed, then alert a warning message
				Context::set('document_srl', null, true);
				$this->dispBoardMessage('msg_not_founded', 404);
			}

		/**
		 * if the document is not existed, get an empty document
		 */
		}
		else
		{
			$oDocument = DocumentModel::getDocument(0);
			$oDocument->add('module_srl', $this->module_srl);
		}

		/**
		 *check the document view grant
		 */
		if($oDocument->isExists())
		{
			if(!$this->grant->view && !$oDocument->isGranted())
			{
				$oDocument = DocumentModel::getDocument(0);
				Context::set('document_srl', null, true);
				$this->dispBoardMessage($this->user->isMember() ? 'msg_not_permitted' : 'msg_not_logged');
			}
			else
			{
				// add the document title to the browser
				Context::setCanonicalURL($oDocument->getPermanentUrl());
				$seo_title = config('seo.document_title') ?: '$SITE_TITLE - $DOCUMENT_TITLE';
				$seo_title = Context::replaceUserLang($seo_title);
				$category_list = Context::get('category_list');
				Context::setBrowserTitle($seo_title, array(
					'site_title' => Context::getSiteTitle(),
					'site_subtitle' => Context::getSiteSubtitle(),
					'subpage_title' => $this->module_info->browser_title,
					'document_title' => $oDocument->getTitleText(),
					'category' => ($oDocument->get('category_srl') && isset($category_list[$oDocument->get('category_srl')])) ? $category_list[$oDocument->get('category_srl')]->title : '',
					'page' => Context::get('page') ?: 1,
				));

				// update the document view count (if the document is not secret)
				if($oDocument->isAccessible())
				{
					$oDocument->updateReadedCount();
				}
				// disappear the document if it is secret
				else
				{
					$oDocument->add('content',lang('thisissecret'));
				}
			}
		}

		Context::set('update_view', $this->grant->update_view);

		// setup the document oject on context
		$oDocument->add('apparent_module_srl', $this->module_srl);
		Context::set('oDocument', $oDocument);

		/**
		 * add javascript filters
		 */
		Context::addJsFilter($this->module_path.'tpl/filter', 'insert_comment.xml');
	}

	/**
	 * @brief  display the document file list (can be used by API)
	 */
	public function dispBoardContentFileList()
	{
		/**
		 * check the access grant (all the grant has been set by the module object)
		 */
		if(!$this->grant->access)
		{
			return $this->dispBoardMessage('msg_not_permitted');
		}

		// check document view grant
		$this->dispBoardContentView();

		// Check if a permission for file download is granted
		// Get configurations (using module model object)
		$file_module_config = ModuleModel::getModulePartConfig('file',$this->module_srl);

		$downloadGrantCount = 0;
		if(is_array($file_module_config->download_grant))
		{
			foreach($file_module_config->download_grant AS $value)
				if($value) $downloadGrantCount++;
		}

		if(is_array($file_module_config->download_grant) && $downloadGrantCount>0)
		{
			if(!Context::get('is_logged'))
			{
				throw new Rhymix\Framework\Exceptions\NotPermitted('msg_not_permitted_download');
			}

			$logged_info = Context::get('logged_info');
			if($logged_info->is_admin != 'Y')
			{
				$columnList = array('module_srl', 'site_srl');
				$module_info = ModuleModel::getModuleInfoByModuleSrl($this->module_srl, $columnList);

				if(!ModuleModel::isSiteAdmin($logged_info, $module_info->site_srl))
				{
					$member_groups = MemberModel::getMemberGroups($logged_info->member_srl, $module_info->site_srl);

					$is_permitted = false;
					for($i=0;$i<count($file_module_config->download_grant);$i++)
					{
						$group_srl = $file_module_config->download_grant[$i];
						if($member_groups[$group_srl])
						{
							$is_permitted = true;
							break;
						}
					}
					if(!$is_permitted)
					{
						throw new Rhymix\Framework\Exceptions\NotPermitted('msg_not_permitted_download');
					}
				}
			}
		}

		$document_srl = Context::get('document_srl');
		$oDocument = DocumentModel::getDocument($document_srl);
		Context::set('oDocument', $oDocument);
		Context::set('file_list',$oDocument->getUploadedFiles());

		$oSecurity = new Security();
		$oSecurity->encodeHTML('file_list..source_filename');
	}

	/**
	 * @brief display the document comment list (can be used by API)
	 */
	public function dispBoardContentCommentList()
	{
		// check document view grant
		$this->dispBoardContentView();

		$document_srl = Context::get('document_srl');
		$oDocument = DocumentModel::getDocument($document_srl);
		$comment_list = $oDocument->getComments();

		// setup the comment list
		if(is_array($comment_list))
		{
			foreach($comment_list as $key => $val)
			{
				if(!$val->isAccessible())
				{
					$val->add('content',lang('thisissecret'));
				}
			}
		}
		Context::set('comment_list',$comment_list);

	}

	/**
	 * @brief display notice list (can be used by API)
	 */
	public function dispBoardNoticeList()
	{
		// check the grant
		if(!$this->grant->list || (Context::get('document_srl') && $this->module_info->use_bottom_list === 'N'))
		{
			Context::set('notice_list', array());
			return;
		}

		$args = new stdClass();
		if (isset($this->module_info->include_notice) && $this->module_info->include_notice === 'N')
		{
			$args->module_srl = $this->module_srl;
		}
		else
		{
			$args->module_srl = $this->include_modules ?: $this->module_srl;
		}
		$output = DocumentModel::getNoticeList($args, $this->columnList);
		$notice_list = $output->data ?? [];

		$this->_fillModuleTitles($notice_list);
		Context::set('notice_list', $notice_list);
	}

	/**
	 * @brief display board content list
	 */
	public function dispBoardContentList()
	{
		// check the grant
		if(!$this->grant->list || (Context::get('document_srl') && $this->module_info->use_bottom_list === 'N'))
		{
			Context::set('document_list', array());
			Context::set('total_count', 0);
			Context::set('total_page', 1);
			Context::set('page', 1);
			Context::set('page_navigation', new PageHandler(0,0,1,10));
			return;
		}

		// Setup basic parameters such as module and page.
		$args = new stdClass();
		$args->module_srl = $this->include_modules ?: $this->module_srl;
		$args->page = intval(Context::get('page')) ?: null;
		$args->list_count = $this->list_count;
		$args->page_count = $this->page_count;
		if(Context::get('v_mode') == 'recommended') $args->s_voted_count = Rhymix\Modules\Yeokbox\Models\Config::getVoteCount();
		if (isset($this->module_info->include_days) && $this->module_info->include_days > 0)
		{
			$args->start_regdate = date('YmdHis', time() - ($this->module_info->include_days * 86400));
		}

		// Filter by search target and keyword.
		if ($this->grant->view)
		{
			$args->search_target = (string)Context::get('search_target');
			$args->search_keyword = (string)Context::get('search_keyword');

			// Remove unsupported search target
			$search_option = Context::get('search_option') ?: $this->search_option;
			if ($args->search_target !== '' && !isset($search_option[$args->search_target]))
			{
				$args->search_target = '';
				$args->search_keyword = '';
			}
		}

		// Filter by category.
		if ($this->module_info->use_category === 'Y')
		{
			$args->category_srl = (string)Context::get('category') ?: null;

			// Support comma-separated categories #2519
			if ($args->category_srl)
			{
				$args->category_srl = array_map('intval', explode(',', $args->category_srl));
				if (count($args->category_srl) === 1)
				{
					$args->category_srl = $args->category_srl[0];
				}
			}
		}

		// Filter by consultation member_srl, or the member_srl parameter if given.
		if ($this->consultation)
		{
			if ($this->module_info->use_anonymous === 'Y')
			{
				$args->member_srl = [$this->user->member_srl, $this->user->member_srl * -1];
			}
			else
			{
				$args->member_srl = $this->user->member_srl;
			}
		}
		else
		{
			if ($this->module_info->use_anonymous !== 'Y')
			{
				$args->member_srl = abs(intval(Context::get('member_srl'))) ?: null;
			}
		}

		// If we are filtering by category or search keyword, use search_list_count instead of list_count.
		if (!empty($args->category_srl) || !empty($args->search_keyword))
		{
			$args->list_count = $this->search_list_count;
		}

		// Setup sorting.
		$args->sort_index = (string)Context::get('sort_index');
		$args->order_type = (string)Context::get('order_type');
		if (!in_array($args->sort_index, $this->order_target ?? []))
		{
			$args->sort_index = $this->module_info->order_target ?: 'list_order';
		}
		if (!in_array($args->order_type, ['asc', 'desc']))
		{
			$args->order_type = $this->module_info->order_type ?: 'asc';
		}

		// Find the page on which the current document is located.
		// This is very resource-intensive, so we only do it when necessary.
		$document_srl = (int)Context::get('document_srl') ?: null;
		if($document_srl && $this->module_info->skip_bottom_list_for_robot !== 'N' && isCrawler())
		{
			Context::set('page', $args->page = null);
		}
		elseif(!$args->page && $document_srl)
		{
			$oDocument = DocumentModel::getDocument($document_srl);
			if($oDocument->isExists() && !$oDocument->isNotice())
			{
				$days = $this->module_info->skip_bottom_list_days ?: 30;
				if($oDocument->getRegdateTime() < (time() - (86400 * $days)) && $this->module_info->skip_bottom_list_for_olddoc === 'Y')
				{
					Context::set('page', $args->page = null);
				}
				else
				{
					$args->except_notice = $this->except_notice;
					$args->page = DocumentModel::getDocumentPage($oDocument, $args);
					Context::set('page', $args->page);
				}
			}
		}

		// setup the list config variable on context
		Context::set('list_config', $this->listConfig);

		// setup document list variables on context
		$output = DocumentModel::getDocumentList($args, $this->except_notice, TRUE, $this->columnList);
		$this->_fillModuleTitles($output->data);
		Context::set('document_list', $output->data);
		Context::set('total_count', $output->total_count);
		Context::set('total_page', $output->total_page);
		Context::set('page', $output->page);
		Context::set('page_navigation', $output->page_navigation);
	}

	public function _fillModuleTitles(&$document_list)
	{
		static $map = null;

		if (!$document_list)
		{
			return;
		}

		if ($this->include_modules)
		{
			if ($map === null)
			{
				$map = [];
				$module_titles = ModuleModel::getModulesInfo($this->include_modules, ['module_srl', 'mid', 'browser_title']);
				foreach ($module_titles as $module_info)
				{
					$map[$module_info->module_srl] = $module_info;
				}
			}
			foreach ($document_list as $document)
			{
				$module_srl = $document->get('module_srl');
				if ($document->get('mid') === null)
				{
					$document->add('apparent_module_srl', $this->module_info->module_srl);
					$document->add('module_title', isset($map[$module_srl]) ? $map[$module_srl]->browser_title : $this->module_info->browser_title);
					$document->add('mid', isset($map[$module_srl]) ? $map[$module_srl]->mid : $this->module_info->mid);
				}
			}
		}
		else
		{
			foreach ($document_list as $document)
			{
				if ($document->get('mid') === null)
				{
					$document->add('module_title', $this->module_info->browser_title);
					$document->add('mid', $this->module_info->mid);
				}
			}
		}
	}

	public function _makeListColumnList()
	{
		// List of all available columns
		$allColumnList = array(
			'document_srl', 'module_srl', 'category_srl', 'lang_code', 'is_notice',
			'title', 'title_bold', 'title_color', 'content',
			'readed_count', 'voted_count', 'blamed_count', 'comment_count', 'trackback_count', 'uploaded_count',
			'password', 'user_id', 'user_name', 'nick_name', 'member_srl', 'email_address', 'homepage', 'tags', 'extra_vars',
			'regdate', 'last_update', 'last_updater', 'ipaddress', 'list_order', 'update_order',
			'allow_trackback', 'notify_message', 'status', 'comment_status',
		);

		// List of columns that should always be selected
		$defaultColumnList = array(
			'document_srl', 'module_srl', 'category_srl', 'lang_code', 'is_notice',
			'title', 'title_bold', 'title_color', 'member_srl', 'nick_name', 'tags', 'extra_vars',
			'comment_count', 'trackback_count', 'uploaded_count', 'status', 'regdate', 'last_update',
		);

		// List of columns selected by the user
		$selectedColumnList = array_keys($this->listConfig);
		if ($this->grant->manager)
		{
			$selectedColumnList[] = 'ipaddress';
		}

		// Return all columns for some legacy skins
		if($this->module_info->skin == 'xe_guestbook' || ($this->module_info->default_style ?? '') == 'blog')
		{
			$this->columnList = $allColumnList;
		}
		else
		{
			// Convert some special names to corresponding column names
			if(in_array('summary', $selectedColumnList))
			{
				$selectedColumnList[] = 'content';
			}
			if(in_array('last_post', $selectedColumnList))
			{
				$selectedColumnList[] = 'last_updater';
			}

			// Remove duplicates and/or invalid column names
			$selectedColumnList = array_intersect($selectedColumnList, $allColumnList);
			$this->columnList = array_unique(array_merge($defaultColumnList, $selectedColumnList));
		}

		// add table name
		foreach($this->columnList as $no => $value)
		{
			$this->columnList[$no] = 'documents.' . $value;
		}
	}

	/**
	 * @brief display tag list
	 */
	public function dispBoardTagList()
	{
		// check if there is not grant fot view list, then alert an warning message
		if(!$this->grant->list)
		{
			return $this->dispBoardMessage('msg_not_permitted');
		}

		$obj = new stdClass;
		if (empty($this->include_modules))
		{
			$obj->module_srl = $this->module_info->module_srl;
		}
		else
		{
			$obj->module_srl = $this->include_modules;
		}
		$obj->list_count = 10000;
		$output = TagModel::getTagList($obj);

		// automatically order
		if(count($output->data))
		{
			$numbers = array_keys($output->data);
			shuffle($numbers);

			if(count($output->data))
			{
				foreach($numbers as $k => $v)
				{
					$tag_list[] = $output->data[$v];
				}
			}
		}

		Context::set('tag_list', $tag_list);

		$oSecurity = new Security();
		$oSecurity->encodeHTML('tag_list.');

		$this->setTemplateFile('tag_list');
	}

	/**
	 * @brief display category list
	 */
	public function dispBoardCategory()
	{
		$this->dispBoardCategoryList();
		$this->setTemplateFile('category.html');
	}

	/**
	 * @brief display comment page
	 */
	public function dispBoardCommentPage()
	{
		$document_srl = Context::get('document_srl');
		if(!$document_srl)
		{
			throw new Rhymix\Framework\Exceptions\InvalidRequest;
		}

		if($this->grant->view == false || ($this->module_info->consultation == 'Y' && !$this->grant->manager && !$this->grant->consultation_read))
		{
			throw new Rhymix\Framework\Exceptions\NotPermitted;
		}

		$oDocument = DocumentModel::getDocument($document_srl);
		if(!$oDocument->isExists())
		{
			throw new Rhymix\Framework\Exceptions\TargetNotFound;
		}
		Context::set('oDocument', $oDocument);

		$this->setLayoutPath('./common/tpl');
		$this->setLayoutFile('default_layout');
		$this->setTemplateFile('comment.html');
	}

	/**
	 * @brief display document write form
	 */
	public function dispBoardWrite()
	{
		// check grant
		if(!$this->grant->write_document)
		{
			return $this->dispBoardMessage($this->user->isMember() ? 'msg_not_permitted' : 'msg_not_logged');
		}

		// Fix any missing module configurations
		BoardModel::fixModuleConfig($this->module_info);

		/**
		 * check if the category option is enabled not not
		 */
		if ($this->module_info->use_category === 'Y')
		{
			// get the user group information
			if(Context::get('is_logged'))
			{
				$group_srls = array_keys($this->user->group_list);
			}
			else
			{
				$group_srls = array();
			}

			// check the grant after obtained the category list
			$category_list = array();
			$normal_category_list = DocumentModel::getCategoryList($this->module_srl);
			if(count($normal_category_list))
			{
				foreach($normal_category_list as $category_srl => $category)
				{
					$is_granted = TRUE;
					if(isset($category->group_srls) && $category->group_srls)
					{
						$category_group_srls = explode(',',$category->group_srls);
						$is_granted = FALSE;
						if(count(array_intersect($group_srls, $category_group_srls))) $is_granted = TRUE;

					}
					if($is_granted) $category_list[$category_srl] = $category;
				}
			}

			// check if at least one category is granted
			$grant_exists = false;
			foreach ($category_list as $category)
			{
				if ($category->grant)
				{
					$grant_exists = true;
				}
			}
			if ($grant_exists)
			{
				Context::set('category_list', $category_list);
			}
			else
			{
				$this->module_info->use_category = 'N';
				Context::set('category_list', array());
			}
		}

		// GET parameter document_srl from request
		$document_srl = Context::get('document_srl');
		$oDocument = DocumentModel::getDocument(0);
		$oDocument->setDocument($document_srl);

		$savedDoc = ($oDocument->get('module_srl') == $oDocument->get('member_srl'));
		$oDocument->add('origin_module_srl', $oDocument->get('module_srl'));
		$oDocument->add('module_srl', $this->module_srl);

		if ($oDocument->isExists())
		{
			if ($this->module_info->protect_document_regdate > 0 && $this->grant->manager == false)
			{
				if($oDocument->get('regdate') < date('YmdHis', strtotime('-'.$this->module_info->protect_document_regdate.' day')))
				{
					$format =  lang('msg_protect_regdate_document');
					$massage = sprintf($format, $this->module_info->protect_document_regdate);
					throw new Rhymix\Framework\Exception($massage);
				}
			}
			if ($this->module_info->protect_content === 'Y' || $this->module_info->protect_update_content === 'Y')
			{
				if($oDocument->get('comment_count') > 0 && $this->grant->manager == false)
				{
					throw new Rhymix\Framework\Exception('msg_protect_update_content');
				}
			}

			if ($this->module_info->protect_admin_content_update === 'Y')
			{
				$member_info = MemberModel::getMemberInfo($oDocument->get('member_srl'));
				if(isset($member_info->is_admin) && $member_info->is_admin == 'Y' && $this->user->is_admin != 'Y')
				{
					throw new Rhymix\Framework\Exception('msg_admin_document_no_modify');
				}
			}
		}

		// if the document is not granted, then back to the password input form
		if($oDocument->isExists() && !$oDocument->isGranted())
		{
			if ($oDocument->getMemberSrl())
			{
				return $this->dispBoardMessage('msg_not_permitted');
			}
			else
			{
				return $this->setTemplateFile('input_password_form');
			}
		}

		if(!$oDocument->isExists())
		{
			$point_config = ModuleModel::getModulePartConfig('point',$this->module_srl);
			if ($point_config)
			{
				$pointForInsert = intval(is_object($point_config) ? $point_config->insert_document : $point_config["insert_document"]);
			}
			else
			{
				$pointForInsert = 0;
			}

			if($pointForInsert < 0)
			{
				if(!$this->user->isMember())
				{
					return $this->dispBoardMessage('msg_not_permitted');
				}
				else if((PointModel::getPoint($this->user->member_srl) + $pointForInsert) < 0)
				{
					return $this->dispBoardMessage('msg_not_enough_point');
				}
			}
		}
		if(!$oDocument->get('status')) $oDocument->add('status', DocumentModel::getDefaultStatus());

		$statusList = $this->_getStatusNameList();
		if(count($statusList) > 0) Context::set('status_list', $statusList);

		// get Document status config value
		Context::set('document_srl',$document_srl);
		Context::set('oDocument', $oDocument);

		// apply xml_js_filter on header
		$oDocumentController = DocumentController::getInstance();
		$oDocumentController->addXmlJsFilter($this->module_info->module_srl);

		// if the document exists, then setup extra variabels on context
		if ($oDocument->isExists() && !$savedDoc)
		{
			Context::set('extra_keys', $oDocument->getExtraVars());
		}

		/**
		 * add JS filters
		 */
		if($this->grant->manager || $this->module_info->allow_no_category == 'Y')
		{
			Context::addJsFilter($this->module_path.'tpl/filter', 'insert_admin.xml');
		}
		else
		{
			Context::addJsFilter($this->module_path.'tpl/filter', 'insert.xml');
		}

		$oSecurity = new Security();
		$oSecurity->encodeHTML('category_list.text', 'category_list.title');

		$this->setTemplateFile('write_form');
	}

	public function _getStatusNameList()
	{
		$resultList = array();
		if(!empty($this->module_info->use_status))
		{
			$statusNameList = DocumentModel::getStatusNameList();
			$statusList = explode('|@|', $this->module_info->use_status);

			if(is_array($statusList))
			{
				foreach($statusList as $key => $value)
				{
					$resultList[$value] = $statusNameList[$value];
				}
			}
		}
		return $resultList;
	}

	/**
	 * @brief display board module deletion form
	 */
	public function dispBoardDelete()
	{
		// check grant
		if(!$this->grant->write_document)
		{
			return $this->dispBoardMessage($this->user->isMember() ? 'msg_not_permitted' : 'msg_not_logged');
		}

		// get the document_srl from request
		$document_srl = Context::get('document_srl');

		// if document exists, get the document information
		if($document_srl)
		{
			$oDocument = DocumentModel::getDocument($document_srl);
		}
		else
		{
			throw new Rhymix\Framework\Exceptions\InvalidRequest;
		}

		// if the document does not exist
		if(!$oDocument || !$oDocument->isExists())
		{
			throw new Rhymix\Framework\Exceptions\TargetNotFound;
		}

		// if the document is not granted, then back to the password input form
		if(!$oDocument->isGranted())
		{
			if ($oDocument->getMemberSrl())
			{
				return $this->dispBoardMessage('msg_not_permitted');
			}
			else
			{
				return $this->setTemplateFile('input_password_form');
			}
		}

		// Fix any missing module configurations
		BoardModel::fixModuleConfig($this->module_info);

		if($this->module_info->protect_document_regdate > 0 && $this->grant->manager == false)
		{
			if($oDocument->get('regdate') < date('YmdHis', strtotime('-'.$this->module_info->protect_document_regdate.' day')))
			{
				$format =  lang('msg_protect_regdate_document');
				$massage = sprintf($format, $this->module_info->protect_document_regdate);
				throw new Rhymix\Framework\Exception($massage);
			}
		}

		if($this->module_info->protect_content == "Y" || $this->module_info->protect_delete_content == 'Y')
		{
			if($oDocument->get('comment_count')>0 && $this->grant->manager == false)
			{
				throw new Rhymix\Framework\Exception('msg_protect_delete_content');
			}
		}

		if ($this->module_info->protect_admin_content_delete !== 'N')
		{
			$member_info = MemberModel::getMemberInfo($oDocument->get('member_srl'));
			if(isset($member_info->is_admin) && $member_info->is_admin == 'Y' && $this->user->is_admin != 'Y')
			{
				throw new Rhymix\Framework\Exception('document.msg_document_is_admin_not_permitted');
			}
		}

		Context::set('oDocument', $oDocument);

		/**
		 * add JS filters
		 */
		Context::addJsFilter($this->module_path.'tpl/filter', 'delete_document.xml');

		$this->setTemplateFile('delete_form');
	}

	/**
	 * @brief display comment wirte form
	 */
	public function dispBoardWriteComment()
	{
		$document_srl = Context::get('document_srl');

		// check grant
		if(!$this->grant->write_comment)
		{
			return $this->dispBoardMessage($this->user->isMember() ? 'msg_not_permitted' : 'msg_not_logged');
		}

		// get the document information
		$oDocument = DocumentModel::getDocument($document_srl);
		if(!$oDocument->isExists())
		{
			return $this->dispBoardMessage('msg_not_founded', 404);
		}

		// Check allow comment
		if(!$oDocument->allowComment())
		{
			return $this->dispBoardMessage('msg_not_allow_comment');
		}

		// obtain the comment (create an empty comment document for comment_form usage)
		$oSourceComment = $oComment = CommentModel::getComment(0);
		$oComment->add('document_srl', $document_srl);
		$oComment->add('module_srl', $this->module_srl);

		// setup document variables on context
		Context::set('oDocument',$oDocument);
		Context::set('oSourceComment',$oSourceComment);
		Context::set('oComment',$oComment);

		/**
		 * add JS filter
		 */
		Context::addJsFilter($this->module_path.'tpl/filter', 'insert_comment.xml');

		$this->setTemplateFile('comment_form');
	}

	/**
	 * @brief display comment replies page
	 */
	public function dispBoardReplyComment()
	{
		// check grant
		if(!$this->grant->write_comment)
		{
			return $this->dispBoardMessage($this->user->isMember() ? 'msg_not_permitted' : 'msg_not_logged');
		}

		// get the parent comment ID
		$parent_srl = Context::get('comment_srl');

		// if the parent comment is not existed
		if(!$parent_srl)
		{
			throw new Rhymix\Framework\Exceptions\InvalidRequest;
		}

		// get the comment
		$oSourceComment = CommentModel::getComment($parent_srl);

		// if the comment is not existed, opoup an error message
		if(!$oSourceComment->isExists())
		{
			return $this->dispBoardMessage('msg_not_founded', 404);
		}
		if(Context::get('document_srl') && $oSourceComment->get('document_srl') != Context::get('document_srl'))
		{
			return $this->dispBoardMessage('msg_not_founded', 404);
		}

		// Check thread depth
		$comment_config = ModuleModel::getModulePartConfig('comment', $this->module_srl);
		if (isset($comment_config->max_thread_depth) && $comment_config->max_thread_depth > 0)
		{
			$parent_depth = CommentModel::getCommentDepth($parent_srl);
			if ($parent_depth + 2 > $comment_config->max_thread_depth)
			{
				return $this->dispBoardMessage('msg_exceeds_max_thread_depth');
			}
		}

		// Check allow comment
		$oDocument = DocumentModel::getDocument($oSourceComment->get('document_srl'));
		if(!$oDocument->allowComment())
		{
			return $this->dispBoardMessage('msg_not_allow_comment');
		}

		// get the comment information
		$oComment = CommentModel::getComment(0);
		$oComment->add('parent_srl', $parent_srl);
		$oComment->add('document_srl', $oSourceComment->get('document_srl'));

		// setup comment variables
		Context::set('oSourceComment',$oSourceComment);
		Context::set('oComment',$oComment);
		Context::set('module_srl',$this->module_info->module_srl);

		/**
		 * add JS filters
		 */
		Context::addJsFilter($this->module_path.'tpl/filter', 'insert_comment.xml');

		$this->setTemplateFile('comment_form');
	}

	/**
	 * @brief display the comment modification from
	 */
	public function dispBoardModifyComment()
	{
		// check grant
		if(!$this->grant->write_comment)
		{
			return $this->dispBoardMessage($this->user->isMember() ? 'msg_not_permitted' : 'msg_not_logged');
		}

		// get the document_srl and comment_srl
		$document_srl = Context::get('document_srl');
		$comment_srl = Context::get('comment_srl');

		// if the comment is not existed
		if(!$comment_srl)
		{
			throw new Rhymix\Framework\Exceptions\InvalidRequest;
		}

		// get comment information
		$oComment = CommentModel::getComment($comment_srl);

		// if the comment is not exited, alert an error message
		if(!$oComment->isExists())
		{
			return $this->dispBoardMessage('msg_not_founded');
		}

		// if the comment is not granted, then back to the password input form
		if(!$oComment->isGranted())
		{
			Context::set('document_srl', $oComment->get('document_srl'));
			if ($oComment->getMemberSrl())
			{
				return $this->dispBoardMessage('msg_not_permitted');
			}
			else
			{
				return $this->setTemplateFile('input_password_form');
			}
		}

		// Fix any missing module configurations
		BoardModel::fixModuleConfig($this->module_info);

		if($this->module_info->protect_comment_regdate > 0 && $this->grant->manager == false)
		{
			if($oComment->get('regdate') < date('YmdHis', strtotime('-'.$this->module_info->protect_document_regdate.' day')))
			{
				$format =  lang('msg_protect_regdate_comment');
				$massage = sprintf($format, $this->module_info->protect_document_regdate);
				throw new Rhymix\Framework\Exception($massage);
			}
		}
		if($this->module_info->protect_update_comment === 'Y' && $this->grant->manager == false)
		{
			$childs = CommentModel::getChildComments($comment_srl);
			if(count($childs) > 0)
			{
				throw new Rhymix\Framework\Exception('msg_board_update_protect_comment');
			}
		}

		$logged_info = Context::get('logged_info');
		if ($this->module_info->protect_admin_content_update !== 'N' && $logged_info->is_admin !== 'Y' && $logged_info->member_srl !== $oComment->member_srl)
		{
			$member_info = MemberModel::getMemberInfo($oComment->member_srl);
			if($member_info->is_admin === 'Y')
			{
				throw new Rhymix\Framework\Exception('msg_admin_comment_no_modify');
			}
		}

		// setup the comment variables on context
		Context::set('oSourceComment', CommentModel::getComment());
		Context::set('oComment', $oComment);

		/**
		 * add JS fitlers
		 */
		Context::addJsFilter($this->module_path.'tpl/filter', 'insert_comment.xml');

		$this->setTemplateFile('comment_form');
	}

	/**
	 * @brief display the delete comment  form
	 */
	public function dispBoardDeleteComment()
	{
		// check grant
		if(!$this->grant->write_comment)
		{
			return $this->dispBoardMessage($this->user->isMember() ? 'msg_not_permitted' : 'msg_not_logged');
		}

		// get the comment_srl to be deleted
		$comment_srl = Context::get('comment_srl');

		// if the comment exists, then get the comment information
		if($comment_srl)
		{
			$oComment = CommentModel::getComment($comment_srl);
		}
		else
		{
			throw new Rhymix\Framework\Exceptions\InvalidRequest;
		}

		// if the comment does not exist
		if(!$oComment || !$oComment->isExists())
		{
			throw new Rhymix\Framework\Exceptions\TargetNotFound;
		}

		// if the comment is not granted, then back to the password input form
		if(!$oComment->isGranted())
		{
			Context::set('document_srl', $oComment->get('document_srl'));
			if ($oComment->getMemberSrl())
			{
				return $this->dispBoardMessage('msg_not_permitted');
			}
			else
			{
				return $this->setTemplateFile('input_password_form');
			}
		}

		// Fix any missing module configurations
		BoardModel::fixModuleConfig($this->module_info);

		if($this->module_info->protect_comment_regdate > 0 && $this->grant->manager == false)
		{
			if($oComment->get('regdate') < date('YmdHis', strtotime('-'.$this->module_info->protect_document_regdate.' day')))
			{
				$format =  lang('msg_protect_regdate_comment');
				$massage = sprintf($format, $this->module_info->protect_document_regdate);
				throw new Rhymix\Framework\Exception($massage);
			}
		}

		if($this->module_info->protect_delete_comment === 'Y' && $this->grant->manager == false)
		{
			$childs = CommentModel::getChildComments($comment_srl);
			if(count($childs) > 0)
			{
				throw new Rhymix\Framework\Exception('msg_board_delete_protect_comment');
			}
		}

		$logged_info = Context::get('logged_info');
		if ($this->module_info->protect_admin_content_delete !== 'N' && $logged_info->is_admin !== 'Y' && $logged_info->member_srl !== $oComment->member_srl)
		{
			$member_info = MemberModel::getMemberInfo($oComment->member_srl);
			if($member_info->is_admin === 'Y')
			{
				throw new Rhymix\Framework\Exception('msg_admin_comment_no_delete');
			}
		}

		Context::set('oComment',$oComment);

		/**
		 * add JS filters
		 */
		Context::addJsFilter($this->module_path.'tpl/filter', 'delete_comment.xml');

		$this->setTemplateFile('delete_comment_form');
	}

	/**
	 * @brief display the delete trackback form
	 */
	public function dispBoardDeleteTrackback()
	{
		$oTrackbackModel = getModel('trackback');
		if(!$oTrackbackModel)
		{
			return;
		}

		// get the trackback_srl
		$trackback_srl = Context::get('trackback_srl');

		// get the trackback data
		$columnList = array('trackback_srl');
		$output = $oTrackbackModel->getTrackback($trackback_srl, $columnList);
		$trackback = $output->data;

		// if no trackback, then display the board content
		if(!$trackback)
		{
			return $this->dispBoardContent();
		}

		//Context::set('trackback',$trackback);	//perhaps trackback variables not use in UI

		/**
		 * add JS filters
		 */
		Context::addJsFilter($this->module_path.'tpl/filter', 'delete_trackback.xml');

		$this->setTemplateFile('delete_trackback_form');
	}

	public function dispBoardUpdateLog()
	{
		if($this->grant->update_view !== true)
		{
			throw new Rhymix\Framework\Exceptions\NotPermitted;
		}

		$document_srl = Context::get('document_srl');
		if(!$document_srl)
		{
			throw new Rhymix\Framework\Exceptions\InvalidRequest;
		}

		$updatelog = DocumentModel::getDocumentUpdateLog($document_srl);
		if(!$updatelog->toBool())
		{
			return $updatelog;
		}

		Context::set('total_count', $updatelog->page_navigation->total_count);
		Context::set('total_page', $updatelog->page_navigation->total_page);
		Context::set('page', $updatelog->page);
		Context::set('page_navigation', $updatelog->page_navigation);
		Context::set('updatelog', $updatelog);

		$this->setTemplateFile('update_list');
	}

	public function dispBoardUpdateLogView()
	{
		if($this->grant->update_view !== true)
		{
			throw new Rhymix\Framework\Exceptions\NotPermitted;
		}

		$update_id = Context::get('update_id');
		$update_log = DocumentModel::getUpdateLog($update_id);
		$oDocument = DocumentModel::getDocument($update_log->document_srl);

		$extra_vars = unserialize($update_log->extra_vars);


		$document_extra_array = $oDocument->getExtraVars();
		$extra_html = array();
		foreach ($extra_vars as $extra_key  => $extra)
		{
			foreach ($document_extra_array as $val)
			{
				if($val->name == $extra_key)
				{
					// Use the change the values, it need an other parameters.
					$extra = new ExtraItem($this->module_info->module_srl, $val->idx, $val->name, $val->type, null, '', 'N', 'N', $extra);
					$extra_html[$extra_key] = $extra->getValueHTML();
				}
			}
		}

		Context::addJsFilter($this->module_path.'tpl/filter', 'update.xml');

		Context::set('extra_vars', $extra_html);
		Context::set('update_log', $update_log);

		$this->setTemplateFile('update_view');
	}

	public function dispBoardVoteLog()
	{
		iF($this->grant->vote_log_view !== true)
		{
			throw new Rhymix\Framework\Exceptions\NotPermitted;
		}

		$target = Context::get('target');
		$target_srl = Context::get('target_srl');

		$args = new stdClass();
		if($target === 'document')
		{
			$queryId = 'document.getDocumentVotedLog';
			$args->document_srl = $target_srl;
		}
		elseif($target === 'comment')
		{
			$queryId = 'comment.getCommentVotedLog';
			$args->comment_srl = $target_srl;
		}
		else
		{
			throw new Rhymix\Framework\Exception('msg_not_target');
		}

		$features = Rhymix\Modules\Board\Models\Features::fromModuleInfo($this->module_info);
		if (!$features->{$target}->vote_log)
		{
			throw new Rhymix\Framework\Exceptions\FeatureDisabled;
		}

		$output = executeQueryArray($queryId, $args);
		if(!$output->toBool())
		{
			return $output;
		}

		$vote_member_infos = array();
		$blame_member_infos = array();
		if(count($output->data) > 0)
		{
			foreach($output->data as $key => $log)
			{
				if($log->point > 0)
				{
					if (isset($vote_member_infos[$log->member_srl]))
					{
						continue;
					}
					if (!$features->{$target}->vote_up_log)
					{
						continue;
					}
					$vote_member_infos[$log->member_srl] = MemberModel::getMemberInfo($log->member_srl);
				}
				else
				{
					if (isset($blame_member_infos[$log->member_srl]))
					{
						continue;
					}
					if (!$features->{$target}->vote_down_log)
					{
						continue;
					}
					$blame_member_infos[$log->member_srl] = MemberModel::getMemberInfo($log->member_srl);
				}
			}
		}

		Context::set('board_features', $features);
		Context::set('vote_member_info', $vote_member_infos);
		Context::set('blame_member_info', $blame_member_infos);
		$this->setTemplateFile('vote_log');
	}

	/**
	 * Default 404 Handler.
	 */
	public function dispBoardNotFound()
	{
		$this->dispBoardMessage('msg_not_founded', 404);
	}

	/**
	 * Display an error page.
	 *
	 * @param string $msg_code
	 * @param int $http_code
	 * @return void
	 */
	public function dispBoardMessage($msg_code, $http_code = 403)
	{
		//Context::set('message', lang($msg_code));
		//$this->setTemplateFile('message');
		$oMessageObject = $this instanceof BoardMobile ? MessageMobile::getInstance() : MessageView::getInstance();
		$oMessageObject->setMessage($msg_code);
		$oMessageObject->dispMessage();
		$this->setTemplatePath($oMessageObject->getTemplatePath());
		$this->setTemplateFile($oMessageObject->getTemplateFile());
		$this->setHttpStatusCode($http_code);
	}

	/**
	 * Display an alert window on top of the page.
	 *
	 * @deprecated
	 *
	 * @param string $msg_code
	 * @param int $http_code
	 * @return void
	 */
	public function alertMessage($msg_code, $http_code = 403)
	{
		$script = sprintf('<script> jQuery(function(){ alert(%s); } );</script>', json_encode(lang($msg_code)));
		Context::addHtmlFooter($script);
		$this->setHttpStatusCode($http_code);
	}
}
