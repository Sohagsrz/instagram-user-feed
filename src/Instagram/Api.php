<?php

declare(strict_types=1);

namespace Instagram;

use GuzzleHttp\{Client, ClientInterface};
use Instagram\Utils\CacheHelper;
use GuzzleHttp\Cookie\{SetCookie, CookieJar};
use Instagram\Auth\{Checkpoint\ImapClient, Login, Session};
use Instagram\Exception\InstagramException;
use Instagram\Hydrator\{LocationHydrator,
    MediaHydrator,
    MediaCommentsHydrator,
    ReelsHydrator,
    StoriesHydrator,
    StoryHighlightsHydrator,
    ProfileHydrator,
    HashtagHydrator,
    FollowerHydrator,
    FollowingHydrator,
    LiveHydrator
};
use Instagram\Model\{Location,
    Media,
    MediaDetailed,
    MediaComments,
    Profile,
    Hashtag,
    ProfileStory,
    ReelsFeed,
    StoryHighlights,
    StoryHighlightsFolder,
    FollowerFeed,
    FollowingFeed,
    Live
};
use Instagram\Transport\{HtmlProfileDataFeed,
    JsonMediaDetailedDataFeed,
    JsonMediasDataFeed,
    JsonMediaCommentsFeed,
    JsonHashtagDataFeed,
    JsonProfileDataFeed,
    JsonStoriesDataFeed,
    JsonStoryHighlightsFoldersDataFeed,
    JsonStoryHighlightsStoriesDataFeed,
    JsonMediaDetailedByShortCodeDataFeed,
    JsonFollowerDataFeed,
    JsonFollowingDataFeed,
    FollowUnfollow,
    LikeUnlike,
    LocationData,
    LiveData,
    ReelsData
};
use Psr\Cache\CacheItemPoolInterface;
use Instagram\Utils\InstagramHelper;

class Api
{
    /**
     * @var CacheItemPoolInterface
     */
    protected $cachePool;

    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var Session
     */
    protected $session = null;

    /**
     * @var int
     */
    protected $challengeDelay;

    /**
     * @param CacheItemPoolInterface $cachePool
     * @param ClientInterface|null   $client
     * @param int|null               $challengeDelay
     */
    public function __construct(CacheItemPoolInterface $cachePool, ClientInterface $client = null, ?int $challengeDelay = 3)
    {
        $this->cachePool      = $cachePool;
        $this->client         = $client ?: new Client();
        $this->challengeDelay = $challengeDelay;
    }

    /**
     * @param string          $username
     * @param string          $password
     * @param ImapClient|null $imapClient
     *
     * @throws Exception\InstagramAuthException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function login(string $username, string $password, ?ImapClient $imapClient = null): void
    {
        $login = new Login($this->client, $username, $password, $imapClient, $this->challengeDelay);

        // fetch previous session an re-use it
        $sessionData = $this->cachePool->getItem(Session::SESSION_KEY . '.' . CacheHelper::sanitizeUsername($username));
        $cookies     = $sessionData->get();

        if ($cookies instanceof CookieJar) {
            /** @var SetCookie */
            $session = $cookies->getCookieByName('sessionId');

            // Session expired (should never happened, Instagram TTL is ~ 1 year)
            if ($session->getExpires() < time()) {
                $this->logout($username);
                $this->login($username, $password, $imapClient);
            }

        } else {
            $cookies = $login->process();
            $sessionData->set($cookies);
            $this->cachePool->save($sessionData);
        }

        $this->session = new Session($cookies);
    }

    /**
     * @param string|null $username
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function logout(?string $username = ''): void
    {
        $username = CacheHelper::sanitizeUsername($username);

        $this->cachePool->deleteItem(Session::SESSION_KEY . ($username !== '' ? '.' . $username : ''));
    }

    /**
     * @param string $user
     *
     * @return Profile
     *
     * @throws InstagramException
     */
    public function getProfile(string $user): Profile
    {
        $feed = new HtmlProfileDataFeed($this->client, $this->session);
        $data = $feed->fetchData($user);

        $hydrator = new ProfileHydrator();
        $hydrator->hydrateProfile($data);
        $hydrator->hydrateMedias($data);

        return $hydrator->getProfile();
    }

    /**
     * @param Profile $instagramProfile
     * @param int     $limit
     *
     * @return Profile
     *
     * @throws InstagramException
     */
    public function getMoreMedias(Profile $instagramProfile, int $limit = InstagramHelper::PAGINATION_DEFAULT): Profile
    {
        $feed = new JsonMediasDataFeed($this->client, $this->session);
        $data = $feed->fetchData($instagramProfile, $limit);

        $hydrator = new ProfileHydrator($instagramProfile);
        $hydrator->hydrateMedias($data);

        return $hydrator->getProfile();
    }

    /**
     * @param string $hashtag
     *
     * @return Hashtag
     *
     * @throws InstagramException
     */
    public function getHashtag(string $hashtag): Hashtag
    {
        $feed = new JsonHashtagDataFeed($this->client, $this->session);
        $data = $feed->fetchData($hashtag);

        $hydrator = new HashtagHydrator();
        $hydrator->hydrateHashtag($data);
        $hydrator->hydrateMedias($data);

        return $hydrator->getHashtag();
    }

    /**
     * @param string $hashtag
     * @param string $endCursor
     *
     * @return Hashtag
     *
     * @throws InstagramException
     */
    public function getMoreHashtagMedias(string $hashtag, string $endCursor): Hashtag
    {
        $feed = new JsonHashtagDataFeed($this->client, $this->session);
        $data = $feed->fetchMoreData($hashtag, $endCursor);

        $hydrator = new HashtagHydrator();
        $hydrator->hydrateHashtag($data);
        $hydrator->hydrateMedias($data);

        return $hydrator->getHashtag();
    }

    /**
     * @param int    $userId
     * @param string $endCursor
     * @param int    $limit
     *
     * @return Profile
     *
     * @throws InstagramException
     */
    public function getMoreMediasWithCursor(int $userId, string $endCursor, int $limit = InstagramHelper::PAGINATION_DEFAULT): Profile
    {
        $instagramProfile = new Profile();
        $instagramProfile->setId($userId);
        $instagramProfile->setEndCursor($endCursor);

        return $this->getMoreMedias($instagramProfile, $limit);
    }

    /**
     * @param int $userId
     * @param int $limit
     *
     * @return Profile
     *
     * @throws InstagramException
     */
    public function getMoreMediasWithProfileId(int $userId, int $limit = InstagramHelper::PAGINATION_DEFAULT): Profile
    {
        $instagramProfile = new Profile();
        $instagramProfile->setId($userId);

        return $this->getMoreMedias($instagramProfile, $limit);
    }

    /**
     * @param string $mediaCode
     * @param int    $limit
     *
     * @return MediaComments
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getMediaComments(string $mediaCode, int $limit = InstagramHelper::PAGINATION_DEFAULT): MediaComments
    {
        $feed = new JsonMediaCommentsFeed($this->client, $this->session);
        $data = $feed->fetchData($mediaCode, $limit);

        $hydrator = new MediaCommentsHydrator();
        $hydrator->hydrateMediaComments($data);

        return $hydrator->getMediaComments();
    }

    /**
     * @param string $mediaCode
     * @param string $endCursor
     *
     * @return MediaComments
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getMoreMediaComments(string $mediaCode, string $endCursor): MediaComments
    {
        $feed = new JsonMediaCommentsFeed($this->client, $this->session);
        $data = $feed->fetchMoreData($mediaCode, $endCursor);

        $hydrator = new MediaCommentsHydrator();
        $hydrator->hydrateMediaComments($data);

        return $hydrator->getMediaComments();
    }

    /**
     * @param string $mediaId
     * @param int    $limit
     *
     * @return MediaComments
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getMediaCommentsById(string $mediaId, int $limit = InstagramHelper::PAGINATION_DEFAULT): MediaComments
    {
        $mediaCode = InstagramHelper::getCodeFromId($mediaId);
        return $this->getMediaComments($mediaCode, $limit);
    }

    /**
     * @param int $userId
     *
     * @return ProfileStory
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getStories(int $userId): ProfileStory
    {
        $feed = new JsonStoriesDataFeed($this->client, $this->session);
        $data = $feed->fetchData($userId);

        $hydrator = new StoriesHydrator();
        $hydrator->hydrateStories($data);

        return $hydrator->getStories();
    }

    /**
     * @param int $userId
     *
     * @return StoryHighlights
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getStoryHighlightsFolder(int $userId): StoryHighlights
    {
        $feed = new JsonStoryHighlightsFoldersDataFeed($this->client, $this->session);
        $data = $feed->fetchData($userId);

        $hydrator = new StoryHighlightsHydrator();
        $hydrator->hydrateFolders($data);

        return $hydrator->getHighlights();
    }

    /**
     * @param StoryHighlightsFolder $folder
     *
     * @return StoryHighlightsFolder
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getStoriesOfHighlightsFolder(StoryHighlightsFolder $folder): StoryHighlightsFolder
    {
        $feed = new JsonStoryHighlightsStoriesDataFeed($this->client, $this->session);
        $data = $feed->fetchData($folder);

        $hydrator = new StoryHighlightsHydrator();
        $hydrator->hydrateHighLights($folder, $data);

        return $hydrator->getFolder();
    }

    /**
     * @param Media $media
     *
     * @return MediaDetailed
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getMediaDetailed(Media $media): MediaDetailed
    {
        $feed = new JsonMediaDetailedDataFeed($this->client, $this->session);
        $data = $feed->fetchData($media);

        $hydrator = new MediaHydrator();
        $media    = $hydrator->hydrateMediaDetailed($data->shortcode_media);

        return $media;
    }

    /**
     * @param Media $media
     *
     * @return MediaDetailed
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getMediaDetailedByShortCode(Media $media): MediaDetailed
    {
        $feed = new JsonMediaDetailedByShortCodeDataFeed($this->client, $this->session);
        $data = $feed->fetchData($media);

        $hydrator = new MediaHydrator();
        $media    = $hydrator->hydrateMediaDetailed($data->shortcode_media);

        return $media;
    }

    /**
     * @param int $id
     *
     * @return Profile
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     * @throws InstagramException
     */
    public function getProfileById(int $id): Profile
    {
        $feed     = new JsonProfileDataFeed($this->client, $this->session);
        $userName = $feed->fetchData($id);

        return $this->getProfile($userName);
    }

    /**
     * @param int $id
     *
     * @return FollowerFeed
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getFollowers(int $id): FollowerFeed
    {
        $feed = new JsonFollowerDataFeed($this->client, $this->session);
        $data = $feed->fetchData($id);

        $hydrator = new FollowerHydrator();
        $hydrator->hydrateFollowerFeed($data);
        $hydrator->hydrateUsers($data);

        return $hydrator->getFollowers();
    }

    /**
     * @param int    $id
     * @param string $endCursor
     *
     * @return FollowerFeed
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getMoreFollowers(int $id, string $endCursor): FollowerFeed
    {
        $feed = new JsonFollowerDataFeed($this->client, $this->session);
        $data = $feed->fetchMoreData($id, $endCursor);

        $hydrator = new FollowerHydrator();
        $hydrator->hydrateFollowerFeed($data);
        $hydrator->hydrateUsers($data);

        return $hydrator->getFollowers();
    }

    /**
     * @param int $id
     *
     * @return FollowingFeed
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getFollowings(int $id): FollowingFeed
    {
        $feed = new JsonFollowingDataFeed($this->client, $this->session);
        $data = $feed->fetchData($id);

        $hydrator = new FollowingHydrator();
        $hydrator->hydrateFollowingFeed($data);
        $hydrator->hydrateUsers($data);

        return $hydrator->getFollowings();
    }

    /**
     * @param int    $id
     * @param string $endCursor
     *
     * @return FollowingFeed
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function getMoreFollowings(int $id, string $endCursor): FollowingFeed
    {
        $feed = new JsonFollowingDataFeed($this->client, $this->session);
        $data = $feed->fetchMoreData($id, $endCursor);

        $hydrator = new FollowingHydrator();
        $hydrator->hydrateFollowingFeed($data);
        $hydrator->hydrateUsers($data);

        return $hydrator->getFollowings();
    }

    /**
     * @param int $accountId
     *
     * @return string
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function follow(int $accountId): string
    {
        $request = new FollowUnfollow($this->client, $this->session);
        return $request->follow($accountId);
    }

    /**
     * @param int $accountId
     *
     * @return string
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function unfollow(int $accountId): string
    {
        $request = new FollowUnfollow($this->client, $this->session);
        return $request->unfollow($accountId);
    }

    /**
     * @param int $postId
     *
     * @return string
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function like(int $postId): string
    {
        $request = new LikeUnlike($this->client, $this->session);
        return $request->like($postId);
    }

    /**
     * @param int $postId
     *
     * @return string
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     */
    public function unlike(int $postId): string
    {
        $request = new LikeUnlike($this->client, $this->session);
        return $request->unlike($postId);
    }

    /**
     * @param int $locationId
     *
     * @return Location
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getLocation(int $locationId): Location
    {
        $feed = new LocationData($this->client, $this->session);
        $data = $feed->fetchData($locationId);

        $hydrator = new LocationHydrator();
        $hydrator->hydrateLocation($data);
        $hydrator->hydrateMedias($data);

        return $hydrator->getLocation();
    }

    /**
     * @param int    $locationId
     * @param string $endCursor
     *
     * @return Location
     *
     * @throws InstagramException
     */
    public function getMoreLocationMedias(int $locationId, string $endCursor): Location
    {
        $feed = new LocationData($this->client, $this->session);
        $data = $feed->fetchMoreData($locationId, $endCursor);

        $hydrator = new LocationHydrator();
        $hydrator->hydrateLocation($data);
        $hydrator->hydrateMedias($data);

        return $hydrator->getLocation();
    }

    /**
     * @param string $username
     *
     * @return Live
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getLive(string $username): Live
    {
        $feed = new LiveData($this->client, $this->session);
        $data = $feed->fetchData($username);

        $hydrator = new LiveHydrator();
        $hydrator->liveBaseHydrator($data);

        return $hydrator->getLive();
    }

    /**
     * @param int         $userId
     * @param string|null $maxId
     *
     * @return ReelsFeed
     *
     * @throws Exception\InstagramAuthException
     * @throws Exception\InstagramFetchException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getReels(int $userId, string $maxId = null): ReelsFeed
    {
        $feed = new ReelsData($this->client, $this->session);
        $data = $feed->fetchData($userId, $maxId);

        $hydrator = new ReelsHydrator();
        $hydrator->hydrateReels($data);

        return $hydrator->getReelsFeed();
    }
}
