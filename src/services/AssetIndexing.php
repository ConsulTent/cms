<?php
namespace craft\app\services;

use Craft;
use craft\app\base\Volume;
use craft\app\dates\DateTime;
use craft\app\helpers\StringHelper;
use craft\app\helpers\AssetsHelper;
use craft\app\helpers\IOHelper;
use craft\app\elements\Asset;
use craft\app\models\AssetIndexData as AssetIndexDataModel;
use craft\app\records\AssetFolder;
use craft\app\records\AssetIndexData as AssetIndexDataRecord;
use craft\app\db\Query;
use \yii\base\Component;

/**
 * Class AssetIndexingService
 *
 * @author     Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright  Copyright (c) 2014, Pixel & Tonic, Inc.
 * @license    http://buildwithcraft.com/license Craft License Agreement
 * @see        http://buildwithcraft.com
 * @package    craft.app.services
 * @since      1.0
 * @deprecated This class will have several breaking changes in Craft 3.0.
 */
class AssetIndexing extends Component
{
	// Public Methods
	// =========================================================================

	/**
	 * Returns a unique indexing session id.
	 *
	 * @return string
	 */
	public function getIndexingSessionId()
	{
		return StringHelper::UUID();
	}

	/**
	 * Gets the index list for a volume.
	 *
	 * @param $sessionId
	 * @param $volumeId
	 * @param $directory
	 *
	 * @return array
	 */
	public function prepareIndexList($sessionId, $volumeId, $directory = '')
	{
		try
		{
			$volume = Craft::$app->volumes->getVolumeById($volumeId);

			$fileList = $volume->getFileList($directory);

			$fileList = array_filter($fileList, function($value)
			{
				$path = $value['path'];
				$segments = explode('/', $path);

				foreach ($segments as $segment)
				{
					if (isset($segment[0]) && $segment[0] == '_')
					{
						return false;
					}
				}
				return true;
			});

			// Sort by number of slashes to ensure that parent folders are listed earlier than their children
			usort($fileList, function ($a, $b)
			{
				$a = substr_count($a['path'], '/');
				$b = substr_count($b['path'], '/');

				return ($a == $b ? 0 : ($a < $b ? -1 : 1));
			});

			$bucketFolders = [];
			$skippedFiles = [];
			$offset = 0;
			$total = 0;

			foreach ($fileList as $file)
			{
				$allowedByFilter = !preg_match(AssetsHelper::INDEX_SKIP_ITEMS_PATTERN, $file['basename']);
				$allowedByName = $file['basename'] == AssetsHelper::prepareAssetName($file['basename'], $file['type'] != 'dir');

				if ($allowedByFilter && $allowedByName)
				{
					if ($file['type'] == 'dir')
					{
						$bucketFolders[$file['path']] = true;
					}
					else
					{
						$indexEntry = array(
							'volumeId' => $volumeId,
							'sessionId' => $sessionId,
							'offset' => $offset++,
							'uri' => $file['path'],
							'size' => $file['size'],
							'timestamp' => $file['timestamp']
						);

						$this->storeIndexEntry($indexEntry);
						$total++;
					}
				}
				else
				{
					$skippedFiles[] = $volume->model->name.'/'.$file['path'];
				}
			}

			$indexedFolderIds = array();
			$indexedFolderIds[$this->ensureTopFolder($volume->model)] = true;

			// Ensure folders are in the DB
			foreach ($bucketFolders as $fullPath => $nothing)
			{
				$folderId = Craft::$app->assets->ensureFolderByFullPathAndVolumeId(rtrim($fullPath, '/').'/', $volumeId);
				$indexedFolderIds[$folderId] = true;
			}

			$missingFolders = array();

			$allFolders = Craft::$app->assets->findFolders(array(
				'volumeId' => $volumeId
			));

			foreach ($allFolders as $folderModel)
			{
				if (!isset($indexedFolderIds[$folderModel->id]))
				{
					$missingFolders[$folderModel->id] = $volume->model->name.'/'.$folderModel->path;
				}
			}

			return ['volumeId' => $volumeId, 'total' => $total, 'missingFolders' => $missingFolders, 'skippedFiles' => $skippedFiles];
		}
		catch (\Exception $exception)
		{
			return ['error' => $exception->getMessage()];
		}
	}

	/**
	 * Process index for a volume.
	 *
	 * @param $sessionId
	 * @param $offset
	 * @param $volumeId
	 *
	 * @return mixed
	 */
	public function processIndexForVolume($sessionId, $offset, $volumeId)
	{
		$volume = Craft::$app->volumes->getVolumeById($volumeId);

		$indexEntryModel = $this->getIndexEntry($volumeId, $sessionId, $offset);

		if (empty($indexEntryModel))
		{
			return false;
		}

		$uriPath = $indexEntryModel->uri;
		$asset = $this->_indexFile($volume, $uriPath);

		if ($asset)
		{
			$this->updateIndexEntryRecordId($indexEntryModel->id, $asset->id);

			$asset->size = $indexEntryModel->size;
			$timeModified = new DateTime($indexEntryModel->timestamp);

			if ($asset->kind == 'image')
			{
				$targetPath = $asset->getImageTransformSourcePath();

				if ($asset->dateModified != $timeModified || !IOHelper::fileExists($targetPath))
				{
					if (!$volume->isLocal())
					{
						$volume->saveFileLocally($uriPath, $targetPath);

						// Store the local source for now and set it up for deleting, if needed
						Craft::$app->assetTransforms->storeLocalSource($targetPath);
						Craft::$app->assetTransforms->queueSourceForDeletingIfNecessary($targetPath);
					}

					clearstatcache();
					list ($asset->width, $asset->height) = getimagesize($targetPath);
				}
			}

			$asset->dateModified = $timeModified;

			Craft::$app->assets->saveAsset($asset);

			return ['result' => $asset->id];
		}

		return ['result' => false];
	}

	/**
	 * Ensures a top level folder exists that matches the model.
	 *
	 * @param Volume $model
	 *
	 * @return int
	 */
	public function ensureTopFolder(Volume $model)
	{
		$folder = AssetFolder::findOne(['name' => $model->name,'volumeId' => $model->id]);

		if (empty($folder))
		{
			$folder = new AssetFolder();
			$folder->volumeId = $model->id;
			$folder->parentId = null;
			$folder->name = $model->name;
			$folder->path = '';
			$folder->save();
		}

		return $folder->id;
	}

	/**
	 * Store an index entry.
	 *
	 * @param $data
	 */
	public function storeIndexEntry($data)
	{
		$entry = new AssetIndexDataRecord();

		foreach ($data as $key => $value)
		{
			$entry->setAttribute($key, $value);
		}

		$entry->save();
	}

	/**
	 * Return an index model.
	 *
	 * @param $volumeId
	 * @param $sessionId
	 * @param $offset
	 *
	 * @return AssetIndexDataModel|bool
	 */
	public function getIndexEntry($volumeId, $sessionId, $offset)
	{
		$record = AssetIndexDataRecord::findOne([
				'volumeId' => $volumeId,
				'sessionId' => $sessionId,
				'offset' => $offset
			]
		);

		if ($record)
		{
			return AssetIndexDataModel::create($record);
		}

		return false;
	}

	/**
	 * @param $entryId
	 * @param $recordId
	 *
	 * @return null
	 */
	public function updateIndexEntryRecordId($entryId, $recordId)
	{
		Craft::$app->getDb()->createCommand()->update('{{%assetindexdata}}', array('recordId' => $recordId), array('id' => $entryId))->execute();
	}


	/**
	 * Return a list of missing files for an indexing session.
	 *
	 * @param $volumeIds
	 * @param $sessionId
	 *
	 * @return array
	 */
	public function getMissingFiles($volumeIds, $sessionId)
	{
		$output = array();

		// Load the record IDs of the files that were indexed.
		$processedFiles = (new Query())
			->select('recordId')
			->from('{{%assetindexdata}}')
			->where('sessionId = :sessionId AND recordId IS NOT NULL', array(':sessionId' => $sessionId))
			->column();

		// Flip for faster lookup
		$processedFiles = array_flip($processedFiles);

		$fileEntries = (new Query())
			->select('fi.volumeId, fi.id AS fileId, fi.filename, fo.path, s.name AS volumeName')
			->from('{{%assets}} AS fi')
			->innerJoin('{{%assetfolders}} AS fo', 'fi.folderId = fo.id')
			->innerJoin('{{%assetvolumes}} AS s', 's.id = fi.volumeId')
			->where(array('in', 'fi.volumeId', $volumeIds))
			->all();

		foreach ($fileEntries as $fileEntry)
		{
			if (!isset($processedFiles[$fileEntry['fileId']]))
			{
				$output[$fileEntry['fileId']] = $fileEntry['volumeName'].'/'.$fileEntry['path'].$fileEntry['filename'];
			}
		}

		return $output;
	}

	// Private Methods
	// =========================================================================

	/**
	 * Indexes a file.
	 *
	 * @param Volume $volume  The volume.
	 * @param string $uriPath The URI path fo the file to index.
	 *
	 * @return Asset|bool
	 */
	private function _indexFile($volume, $uriPath)
	{
		$extension = IOHelper::getExtension($uriPath);

		if (IOHelper::isExtensionAllowed($extension))
		{
			$parts = explode('/', $uriPath);
			$filename = array_pop($parts);

			$searchFullPath = join('/', $parts).(empty($parts) ? '' : '/');

			if (empty($searchFullPath))
			{
				$parentId = ':empty:';
			}
			else
			{
				$parentId = false;
			}

			$parentFolder = Craft::$app->assets->findFolder(array(
				'volumeId' => $volume->id,
				'path' => $searchFullPath,
				'parentId' => $parentId
			));

			if (empty($parentFolder))
			{
				return false;
			}

			$folderId = $parentFolder->id;

			$assetModel = Craft::$app->assets->findFile(array(
				'folderId' => $folderId,
				'filename' => $filename
			));

			if (is_null($assetModel))
			{
				$assetModel = new Asset();
				$assetModel->volumeId = $volume->id;
				$assetModel->folderId = $folderId;
				$assetModel->filename = $filename;
				$assetModel->kind = IOHelper::getFileKind($extension);
				$assetModel->indexInProgress = true;
				Craft::$app->assets->saveAsset($assetModel);
			}

			return $assetModel;
		}

		return false;
	}
}
