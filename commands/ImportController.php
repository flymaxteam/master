<?php
/**
 *
 *
 * @author Carsten Brandt <mail@cebe.cc>
 */

namespace app\commands;


use app\models\Comment;
use app\models\News;
use app\models\Rating;
use app\models\Star;
use app\models\User;
use app\models\Wiki;
use app\models\WikiCategory;
use app\models\WikiRevision;
use Faker\Factory;
use yii\console\Controller;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;
use yii\di\Instance;
use yii\helpers\Console;

/**
 * Populates the database with content from the old website
 */
class ImportController extends Controller
{
	public $defaultAction = 'import';

	/**
	 * @var array|Connection
	 */
	public $sourceDb = [
		'class' => Connection::class,
		'dsn' => 'mysql:host=localhost;dbname=yiisite',
		'username' => 'root',
		'password' => '',
		'charset' => 'utf8',
	];

	public $password;

	public function options($actionId)
	{
		return array_merge(parent::options($actionId), ['password']);
	}

	public function init()
	{
		$this->sourceDb = Instance::ensure($this->sourceDb, Connection::class);
		parent::init();
	}

	public function beforeAction($action)
	{
		if ($this->password) {
			$this->sourceDb->password = $this->password;
		}

		return parent::beforeAction($action);
	}



	public function actionImport()
	{
		if (!$this->confirm('Populate database with content from old website?')) {
			return 1;
		}

		$this->importUsers();

		$this->importBadges();

		$this->importWiki();

		// TODO extensions

		$this->importNews();

		$this->importComments();

        $this->importStars();
        $this->importRatings();

		return 0;
	}

	private function importUsers()
	{
		if (User::find()->count() > 0) {
			$this->stdout("Users table is already populated, skipping.\n");
			return;
		}

		// TODO find a way to migrate these accounts
		$duplicateMail = $this->sourceDb->createCommand("SELECT `email` FROM `ipb_members` GROUP BY `email` HAVING COUNT(*) > 1")->queryColumn();
		$duplicateUsername = $this->sourceDb->createCommand("SELECT `name` FROM `ipb_members` GROUP BY `name` HAVING COUNT(*) > 1")->queryColumn();

		//$userQuery = (new Query)->from('tbl_user');
		$userQuery = (new Query)->from('ipb_members')
			->select(['member_id', 'name', 'email', 'joined', 'last_visit', 'last_activity', 'members_display_name', 'members_pass_hash', 'members_pass_salt', 'conv_password'])
			->where(['member_banned' => 0]);

		$count = $userQuery->count('*', $this->sourceDb);
		Console::startProgress(0, $count, 'Importing users...');
		$i = 0;
		foreach($userQuery->each(100, $this->sourceDb) as $user) {

			$yiiUser = (new Query)->from('tbl_user')->where(['id' => $user['member_id']])->one($this->sourceDb);
			if ($yiiUser === false) {
				$this->stdout('NO YII USER for: ' . $user['member_id'] . ' - ' . $user['name']. "\n");
			}
			if ($yiiUser['username'] !== $user['name']) {
				$this->stdout('NAME MISMATCH with YII USER for: ' . $user['member_id'] . ' - ' . $user['name'] . ' - ' . $yiiUser['username'] . "\n");
				continue;
			}
			if (in_array($user['name'], $duplicateUsername)) {
				$this->stdout('NOT IMPORTED DUPLICATE USERNAME: ' . $user['member_id'] . ' - ' . $user['name'] . ' - ' . $user['email'] . "\n");
				continue;
			}
			if (in_array($user['email'], $duplicateMail)) {
				$this->stdout('NOT IMPORTED DUPLICATE EMAIL: ' . $user['member_id'] . ' - ' . $user['name'] . ' - ' . $user['email'] . "\n");
				continue;
			}

			$passwordHash = '';
			if (!empty($user['conv_password'])) {
				$passwordHash = 'LEGACYSHA:' . $user['conv_password'];
			} elseif (!empty($user['members_pass_hash']) && !empty($user['members_pass_salt'])) {
				$passwordHash = 'LEGACYMD5:' . $user['members_pass_hash'] . ':' . $user['members_pass_salt'];
			}

			$model = new User([
				'id' => $user['member_id'],
				'username' => $user['name'],
				'email' => $user['email'],
				'display_name' => empty($user['members_display_name']) ? $user['name'] : $user['members_display_name'],
				'created_at' => date('Y-m-d H:i:s', $user['joined']),
				'password_hash' => $passwordHash,

				'login_time' => date('Y-m-d H:i:s', $yiiUser['login_time']),

				'rank' => $yiiUser['rank'],
				'rating' => $yiiUser['rating'],
				'extension_count' => $yiiUser['extension_count'],
				'wiki_count' => $yiiUser['wiki_count'],
				'comment_count' => $yiiUser['comment_count'],
				'post_count' => $yiiUser['post_count'],

			]);
			$model->detachBehavior('timestamp');
			$model->save(false);

			Console::updateProgress(++$i, $count);
		}
		Console::endProgress(true);
		$this->stdout("done.", Console::FG_GREEN, Console::BOLD);
		$this->stdout(" $count records imported.\n");
	}

	private function importBadges()
	{
		if ((new Query)->from('{{%badges}}')->count() > 0) {
			$this->stdout("Badges table is already populated, skipping.\n");
			return;
		}

		$query = (new Query)->from('tbl_badge');

		$count = $query->count('*', $this->sourceDb);
		Console::startProgress(0, $count, 'Importing badges...');
		$i = 0;
		foreach($query->each(100, $this->sourceDb) as $badge) {

			\Yii::$app->db->createCommand()->insert('{{%badges}}', $badge)->execute();

			Console::updateProgress(++$i, $count);
		}
		Console::endProgress(true);
		$this->stdout("done.", Console::FG_GREEN, Console::BOLD);
		$this->stdout(" $count records imported.\n");

//		$this->importBadgeQueue();
		$this->importUserBadges();
	}

	private function importBadgeQueue()
	{
		$query = (new Query)->from('tbl_badge_queue');

		$count = $query->count('*', $this->sourceDb);
		Console::startProgress(0, $count, 'Importing badge queue...');
		$i = 0;
		foreach($query->each(100, $this->sourceDb) as $badge) {

			\Yii::$app->db->createCommand()->insert('{{%badge_queue}}', $badge)->execute();

			Console::updateProgress(++$i, $count);
		}
		Console::endProgress(true);
		$this->stdout("done.", Console::FG_GREEN, Console::BOLD);
		$this->stdout(" $count records imported.\n");
	}

	private function importUserBadges()
	{
		$query = (new Query)->from('tbl_user_badge');

		$count = $query->count('*', $this->sourceDb);
		Console::startProgress(0, $count, 'Importing user badges...');
		$i = 0;
		foreach($query->each(100, $this->sourceDb) as $badge) {

			$badge['create_time'] = date('Y-m-d H:i:s', $badge['create_time']);
			if ($badge['complete_time'] !== null) {
				$badge['complete_time'] = date('Y-m-d H:i:s', $badge['complete_time']);
			}
			\Yii::$app->db->createCommand()->insert('{{%user_badges}}', $badge)->execute();

			Console::updateProgress(++$i, $count);
		}
		Console::endProgress(true);
		$this->stdout("done.", Console::FG_GREEN, Console::BOLD);
		$this->stdout(" $count records imported.\n");
	}

	private function importWiki()
	{
		if (Wiki::find()->count() > 0 || WikiCategory::find()->count() > 0) {
			$this->stdout("Wiki table is already populated, skipping.\n");
			return;
		}

		// creating wiki categories
		$categoryQuery = (new Query)->from('tbl_lookup')->where(['type' => 'WikiCategory']);
		foreach($categoryQuery->all($this->sourceDb) as $cat) {
			$model = new WikiCategory();
			$model->id = $cat['code'];
			$model->name = $cat['name'];
			$model->sequence = $cat['sequence'];
			$model->save(false);
		}

		// import wikis
		$wikiQuery = (new Query)->from('tbl_wiki')->orderBy('id');
		$count = $wikiQuery->count('*', $this->sourceDb);
		Console::startProgress(0, $count, 'Importing wiki...');
		$i = 0;
		$err = 0;
		foreach($wikiQuery->each(100, $this->sourceDb) as $wiki) {

			try {
				$model = new Wiki([
					'id' => $wiki['id'],
					'title' => $wiki['title'],
					'content' => $this->convertMarkdown($wiki['content']),
					'category_id' => $wiki['category_id'],
					'tagNames' => $wiki['tags'],
					'creator_id' => $wiki['creator_id'],
					'updater_id' => $wiki['updater_id'],
					'created_at' => date('Y-m-d H:i:s', $wiki['create_time']),
					'updated_at' => date('Y-m-d H:i:s', $wiki['update_time']),

					'yii_version' => $wiki['yii_version'],

					'total_votes' => $wiki['total_votes'],
					'up_votes' => $wiki['up_votes'],
					'rating' => $wiki['rating'],

					'view_count' => $wiki['view_count'],
					'comment_count' => $wiki['comment_count'],

					'status' => $wiki['status'],
					'featured' => $wiki['featured'],
				]);
				$model->detachBehavior('timestamp');
				$model->detachBehavior('blameable');
				$model->save(false);

				// remove first revision automatically created by Wiki model
				WikiRevision::deleteAll(['wiki_id' => $wiki['id']]);
				// import revisions:
				$revisionQuery = (new Query)->from('tbl_wiki_revision')->where(['wiki_id' => $wiki['id']]);
				foreach ($revisionQuery->all($this->sourceDb) as $rev) {
					$revModel = new WikiRevision([
						'wiki_id' => $rev['wiki_id'],
						'revision' => $rev['revision'],
						'title' => $rev['title'],
						'content' => $this->convertMarkdown($rev['content']),
						'tagNames' => $rev['tags'],
						'category_id' => $rev['category_id'],
						'memo' => !empty($rev['memo']) ? $rev['memo'] : '',
						'updater_id' => $rev['updater_id'],
						'updated_at' => date('Y-m-d H:i:s', $rev['update_time']),
					]);
					$revModel->detachBehavior('timestamp');
					$revModel->detachBehavior('blameable');
					$revModel->save(false);
				}
			}catch (\Exception $e) {
				$this->stdout($e->getMessage()."\n", Console::FG_RED);
				$err++;
			}
			Console::updateProgress(++$i, $count);
		}
		Console::endProgress(true);
		$this->stdout("done.", Console::FG_GREEN, Console::BOLD);
		$this->stdout(" $count records imported.");
		if ($err > 0) {
			$this->stdout(" $err errors occurred.", Console::FG_RED, Console::BOLD);
		}
		$this->stdout("\n");
	}

	private function importComments()
	{
		if (Comment::find()->count() > 0) {
			$this->stdout("Comment table is already populated, skipping.\n");
			return;
		}

		$query = (new Query)->from('tbl_comment');

		$count = $query->count('*', $this->sourceDb);
		Console::startProgress(0, $count, 'Importing comments...');
		$i = 0;
		$err = 0;
		foreach($query->each(100, $this->sourceDb) as $comment) {

			try {
				\Yii::$app->db->createCommand()->insert('{{%comment}}', [
					'id' => $comment['id'],
					'user_id' => $comment['creator_id'],
					'object_type' => $this->convertCommentType($comment['object_type']),
					'object_id' => $comment['object_id'],
					'text' => (empty($comment['title']) ? '' : '#### ' . $comment['title'] . "\n\n")
						. $this->convertMarkdown($comment['content']),
					'created_at' => date('Y-m-d H:i:s', $comment['create_time']),
					'updated_at' => date('Y-m-d H:i:s', $comment['update_time']),
					'total_votes' => $comment['total_votes'],
					'up_votes' => $comment['up_votes'],
					'rating' => $comment['rating'],
					'status' => $this->convertCommentStatus($comment['status'])
				])->execute();
			}catch (\Exception $e) {
				$err++;
			}
			Console::updateProgress(++$i, $count);
		}
		Console::endProgress(true);
		$this->stdout("done.", Console::FG_GREEN, Console::BOLD);
		$this->stdout(" $count records imported.");
		if ($err > 0) {
			$this->stdout(" $err errors occurred.", Console::FG_RED, Console::BOLD);
		}
		$this->stdout("\n");
	}

	private function convertCommentType($type)
	{
		return $type;
	}

	private function convertCommentStatus($status)
	{
		return $status == 3 ? Comment::STATUS_ACTIVE : Comment::STATUS_DELETED;
	}

	private function importNews()
	{
		if (News::find()->count() > 0) {
			$this->stdout("News table is already populated, skipping.\n");
			return;
		}

		$newsQuery = (new Query)->from('tbl_news');

		$statusMap = [
			/*const STATUS_DRAFT=*/1 => News::STATUS_DRAFT,
			/*const STATUS_PENDING=*/2 => News::STATUS_DRAFT,
			/*const STATUS_PUBLISHED=*/3 => News::STATUS_PUBLISHED,
			/*const STATUS_ARCHIVED=*/4 => News::STATUS_PUBLISHED,
			/*const STATUS_DELETED=*/5 => News::STATUS_DELETED,
		];

		$count = $newsQuery->count('*', $this->sourceDb);
		Console::startProgress(0, $count, 'Importing news...');
		$i = 0;
		foreach($newsQuery->each(100, $this->sourceDb) as $news) {

			$content = $news['content'];
			$content = $this->convertMarkdown($content);

			$model = new News([
				'id' => $news['id'],
				'title' => $news['title'],
				'news_date' => date('Y-m-d', $news['news_date']),
				// TODO image_id
				'content' => $content,
				'status' => $statusMap[$news['status']],
				'creator_id' => $news['creator_id'],
				'created_at' => date('Y-m-d H:i:s', $news['create_time']),
				'updater_id' => $news['updater_id'],
				'updated_at' => date('Y-m-d H:i:s', $news['update_time']),
				'tagNames' => $news['tags'],
			]);
			$model->detachBehavior('timestamp');
			$model->detachBehavior('blameable');
			$model->save(false);

			Console::updateProgress(++$i, $count);
		}
		Console::endProgress(true);
		$this->stdout("done.", Console::FG_GREEN, Console::BOLD);
		$this->stdout(" $count records imported.\n");
	}

	public function importRatings()
	{
		if (Rating::find()->count() > 0) {
			$this->stdout("Rating table is already populated, skipping.\n");
			return;
		}

		$ratingQuery = (new Query)->from('tbl_rating');

		$count = $ratingQuery->count('*', $this->sourceDb);
		Console::startProgress(0, $count, 'Importing ratings...');
		$i = 0;
		$err = 0;
		foreach($ratingQuery->each(100, $this->sourceDb) as $rating) {

			try {
				$rating['created_at'] = date('Y-m-d H:i:s', $rating['create_time']);
				unset($rating['create_time']);
				Rating::getDb()->createCommand()->insert(Rating::tableName(), $rating)->execute();
			} catch (\Exception $e) {
				$this->stdout($e->getMessage()."\n", Console::FG_RED);
				$err++;
			}
			Console::updateProgress(++$i, $count);
		}
		Console::endProgress(true);
		$this->stdout("done.", Console::FG_GREEN, Console::BOLD);
		$this->stdout(" $count records imported.");
		if ($err > 0) {
			$this->stdout(" $err errors occurred.", Console::FG_RED, Console::BOLD);
		}
		$this->stdout("\n");
	}

	public function importStars()
	{
		if (Star::find()->count() > 0) {
			$this->stdout("Star table is already populated, skipping.\n");
			return;
		}

		$starQuery = (new Query)->from('tbl_star');

		$count = $starQuery->count('*', $this->sourceDb);
		Console::startProgress(0, $count, 'Importing stars...');
		$i = 0;
		$err = 0;
		foreach($starQuery->each(100, $this->sourceDb) as $star) {

			try {
				$star['created_at'] = date('Y-m-d H:i:s', $star['create_time']);
				unset($star['create_time']);
				Star::getDb()->createCommand()->insert(Star::tableName(), $star)->execute();
			} catch (\Exception $e) {
				$this->stdout($e->getMessage()."\n", Console::FG_RED);
				$err++;
			}
			Console::updateProgress(++$i, $count);
		}
		Console::endProgress(true);
		$this->stdout("done.", Console::FG_GREEN, Console::BOLD);
		$this->stdout(" $count records imported.");
		if ($err > 0) {
			$this->stdout(" $err errors occurred.", Console::FG_RED, Console::BOLD);
		}
		$this->stdout("\n");
	}

	protected function convertMarkdown($markdown)
	{
		// TODO code blocks conversion does not work inside quotes:
		// http://www.yiiframework.com/wiki/16

		// convert code blocks
		$markdown = preg_replace_callback('/~~~\s*\[php\]\s*(.+?)\n~~~/is', function($matches) {
			return "\n```php\n".$matches[1]."\n```";
		}, $markdown);

		return $markdown;
	}

	protected function getFaker()
	{
		return Factory::create('en');
	}


}