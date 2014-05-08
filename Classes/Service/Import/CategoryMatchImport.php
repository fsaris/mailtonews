<?php

namespace GeorgRinger\Mailtonews\Service\Import;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Frans Saris <franssaris@gmail.com>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Basic implementation of a mail import to news records
 *
 * @author Frans Saris <franssaris@gmail.com>
 */
class CategoryMatchImport extends BasicImport {

	/**
	 * Get all needed data out of the mail
	 * @param \IncomingMail $mail
	 * @return array
	 */
	protected function extractDataFromMail(\IncomingMail $mail) {
		$data = array();

		$configuration = $this->smtpService->getConfiguration();
		if (isset($configuration['defaultValues']) && is_array($configuration['defaultValues'])) {
			foreach ($configuration['defaultValues'] as $fieldName => $value) {
				$data[$fieldName] = $value;
			}
		}

		if (!$this->checkIfAllowed($mail)) {
			$data['hidden'] = TRUE;
		}

		$data['import_id'] = $mail->id;
		$data['import_source'] = $this->smtpService->getHost();
		$data['title'] = $mail->subject;
		$data['datetime'] = strtotime($mail->date);
		$data['author'] = $mail->fromName;
		$data['author_email'] = $mail->fromAddress;

		if (!empty($mail->textHtml)) {
			$data['bodytext'] = $this->cleanUpMessage($mail->textHtml);
		} else {
			$data['bodytext'] = $this->cleanUpMessage($mail->textPlain, TRUE);
		}

		$categoryUid = $this->findCategoryByText($data['title']);

		if ($categoryUid > 0) {
			$data['categories'] = array($categoryUid);
			$data['title'] = '';
		}

		$relatedFiles = $images = array();
		$attachments = $mail->getAttachments();
		foreach ($attachments as $attachment) {
			/** @var \IncomingMailAttachment $attachment */
			$fileInformation = pathinfo($attachment->name);
			if (GeneralUtility::inList($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'], strtolower($fileInformation['extension']))) {
				$images[] = $attachment;
			} else {
				$relatedFiles[] = $attachment;
			}
		}

		$this->handleRelatedFiles($relatedFiles, $data);
		if (is_array($configuration['imagesAsContentElement']) && $configuration['imagesAsContentElement']['_typoScriptNodeValue'] == 1) {
			$this->handleImagesAsContentElement($images, $data);
		} else {
			$this->handleImagesAsMedia($images, $data);
		}
		return $data;
	}

	/**
	 * Find best matching category by string
	 *
	 * @param string $text
	 * @return int category uid
	 */
	protected function findCategoryUid($text) {
		$categoryUid = 0;
		$this->getDatabaseConnection()->debugOutput =1;
		$row = $this->getDatabaseConnection()->exec_SELECTgetSingleRow(
			'uid',
			'sys_category',
			'LOWER(title) LIKE "%' . $this->getDatabaseConnection()->quoteStr(strtolower($text), 'sys_category') . '%" AND hidden=0 AND deleted=0'
		);

		if ($row) {
			$categoryUid = $row['uid'];
		}

		return $categoryUid;
	}

	/**
	 * Find category
	 *
	 * @param $string
	 * @return int
	 */
	protected function findCategoryByText($string) {

		//cleanup string
		$string = preg_replace('/stukje van/i', '', $string);
		$string = preg_replace('/Klokske nieuws/i', '', $string);
		$string = preg_replace('/:/', '', $string);
		$string = preg_replace('/!/', '', $string);
		$string = preg_replace('/klokskenieuws/i', '', $string);
		$string = trim($string);

		if ($string !== '') {
			$catUid = $this->findCategoryUid($string);

			if (!$catUid) {
				$pieces = explode(' ', $string);
				if (count($pieces) > 1) {
					for ($i = 0; $i < count($pieces); $i++) {
						if (strlen($pieces[$i]) > 3) {
							$catUid = $this->findCategoryUid($pieces[$i]);
							if ($catUid > 0) {
								break;
							}
						}
					}
				}
			}

			return $catUid;
		}
	}

	/**
	 * @param string $message
	 * @param bool $plainText
	 * @return string
	 */
	protected function cleanUpMessage($message, $plainText = FALSE) {

		$msg = trim($message);
		$msg = str_replace("\r\n", PHP_EOL, $msg);
		$msg = str_replace("\n", PHP_EOL, $msg);

		// strip msn meuk
		if ($plainText) {
			$msg = str_replace(PHP_EOL, '<br />', $msg);
			if (strpos($msg, '<br />_________________________________________________________________<br />')) {
				$msg = substr($msg, 0, strpos($msg, '<br />_________________________________________________________________<br />'));
			}
		} else {
			if (strpos($msg, '<br clear=all><hr>')) {
				$msg = substr($msg, 0, strpos($msg, '<br clear=all><hr>'));
			}
		}
		$msg = strip_tags($msg, '<p><a><b><strong><i><u><br><br />');
//		$msg = preg_replace('/^(<br \/>)+/i', '', trim($msg));
		return $msg;
	}

	/**
	 * Check if incoming mail is allowed
	 *
	 * @param \IncomingMail $mail
	 * @return bool
	 */
	function checkIfAllowed(\IncomingMail $mail) {
		$return = TRUE;
//		if(trim($email['to']) == 'klokske@drukkerijengelen.nl') {
//			$return = FALSE;
//		} else
		if(preg_match('/fw:/i', $mail->subject)) {
			$return = FALSE;
		} elseif(preg_match('/re:/i', $mail->subject)) {
			$return = FALSE;
		}
		return $return;
	}

	/**
	 * @return \TYPO3\CMS\Core\Database\DatabaseConnection
	 */
	protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}
}