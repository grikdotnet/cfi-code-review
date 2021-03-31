<?php

declare(strict_types=1);

namespace App\Service\VideoPlatform\JwPlatform;

use App\Exception\Generic\InvalidArgumentException;
use App\Service\VideoPlatform\JwPlatform\Request\CreateVideoParams;
use App\Service\VideoPlatform\JwPlatform\Request\CreateVideoTrackParams;
use App\Service\VideoPlatform\JwPlatform\Request\CreateWebhookParams;
use App\Service\VideoPlatform\JwPlatform\Request\DeleteVideosParams;
use App\Service\VideoPlatform\JwPlatform\Request\DeleteVideoTrackParams;
use App\Service\VideoPlatform\JwPlatform\Request\FetchThumbnailParams;
use App\Service\VideoPlatform\JwPlatform\Request\ListTracksParams;
use App\Service\VideoPlatform\JwPlatform\Request\ListVideosParams;
use App\Service\VideoPlatform\JwPlatform\Request\ShowVideoParams;
use App\Service\VideoPlatform\JwPlatform\Request\UpdateVideoThumbnailParams;
use App\Service\VideoPlatform\JwPlatform\Request\VideoStatsParams;
use App\Service\VideoPlatform\JwPlatform\Response\ApiResponse;
use App\Service\VideoPlatform\JwPlatform\Response\CreatedWebhookApiResponse;
use App\Service\VideoPlatform\JwPlatform\Response\DeleteVideosResponse;
use App\Service\VideoPlatform\JwPlatform\Response\DeleteVideoTrackResponse;
use App\Service\VideoPlatform\JwPlatform\Response\ThumbnailResource;
use App\Service\VideoPlatform\JwPlatform\Response\TracksList;
use App\Service\VideoPlatform\JwPlatform\Response\UploadMetadata;
use App\Service\VideoPlatform\JwPlatform\Response\VideoShowResponse;
use App\Service\VideoPlatform\JwPlatform\Response\VideosList;
use App\Service\VideoPlatform\VideoStats;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SymfonySerializerException;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

class JwPlatformApi
{
    private const JWPLAYER_METRICS_FIELD_TOTAL_PLAYS = 'plays';
    private const JWPLAYER_METRICS_FIELD_COMPLETE_RATE = 'complete_rate';
    private const JWPLAYER_METRICS_FIELD_UNIQUE_VIEWERS = 'unique_viewers';
    private const JWPLAYER_METRICS_FIELD_PLAY_RATE = 'play_rate';
    private const JWPLAYER_METRICS_FIELD_EMBEDS = 'embeds';
    private const JWPLAYER_METRICS_FIELD_COMPLETES = 'completes';
    private const JWPLAYER_METRICS_FIELD_CONTENT_SCORE = 'content_score';

    private SerializerInterface $serializer;
    private NormalizerInterface $normalizer;
    private JwPlatformClient $client;
    private string $siteKey;

    public function __construct(
        SerializerInterface $serializer,
        NormalizerInterface $normalizer,
        JwPlatformClient $client,
        string $siteKey
    ) {
        $this->serializer = $serializer;
        $this->normalizer = $normalizer;
        $this->client = $client;
        $this->siteKey = $siteKey;
    }

    /**
     * @see https://developer.jwplayer.com/jwplayer/reference#post_videos-create
     */
    public function createVideoMetadata(CreateVideoParams $params): ApiResponse
    {
        $normalizedParams = $this->normalizer->normalize($params);

        $rawResponse = $this->client->requestV1('/videos/create', $normalizedParams);

        try {
            /** @var ApiResponse $deserialized */
            $deserialized = $this->serializer->deserialize(
                trim($rawResponse),
                ApiResponse::class,
                'json'
            );
        } catch (SymfonySerializerException $exception) {
            throw new InvalidArgumentException(
                'Unable to deserialize: "' . $rawResponse . '"',
                0,
                $exception
            );
        }

        return $deserialized;
    }

    public function createThumbnailUpdateMetadata(UpdateVideoThumbnailParams $params): ApiResponse
    {
        $normalizedParams = $this->normalizer->normalize(
            $params,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]
        );

        $rawResponse = $this->client->requestV1('/videos/thumbnails/update', $normalizedParams);

        try {
            /** @var ApiResponse $deserialized */
            $deserialized = $this->serializer->deserialize(
                trim($rawResponse),
                ApiResponse::class,
                'json'
            );
        } catch (SymfonySerializerException $exception) {
            throw new InvalidArgumentException(
                'Unable to deserialize: "' . $rawResponse . '"',
                0,
                $exception
            );
        }

        return $deserialized;
    }

    public function createTrackCreateMetadata(CreateVideoTrackParams $params): ApiResponse
    {
        $normalizedParams = $this->normalizer->normalize(
            $params,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]
        );

        $rawResponse = $this->client->requestV1('/videos/tracks/create', $normalizedParams);

        try {
            /** @var ApiResponse $deserialized */
            $deserialized = $this->serializer->deserialize(
                trim($rawResponse),
                ApiResponse::class,
                'json'
            );
        } catch (SymfonySerializerException $exception) {
            throw new InvalidArgumentException(
                'Unable to deserialize: "' . $rawResponse . '"',
                0,
                $exception
            );
        }

        return $deserialized;
    }

    public function upload(UploadMetadata $params, string $filePath): void
    {
        $uploadLink = $params->getLink();

        $rawResult = $this->client->uploadFile($filePath, $uploadLink);
    }

    public function fetchAnalytics(
        VideoStatsParams $params
    ): VideoStats
    {
        $path = "/sites/{$this->siteKey}/analytics/queries/";

        $normalizedParams = $this->normalizer->normalize($params);
        $rawResponse = $this->client->requestV2($path, $normalizedParams);

        /** @var \stdClass $responseJson */
        $responseJson = json_decode($rawResponse);
        if (!isset($responseJson->metadata->column_headers->metrics)) {
            throw new InvalidArgumentException('Unexpected JWPlayer analytics metrics response: ' . $rawResponse);
        }

        $videoStats = new VideoStats();

        if (count($responseJson->data->rows)) {
            $jwPlayerRows = $responseJson->data->rows[0];
            if ($jwPlayerRows[0] !== $params->getMediaId()) {
                throw new InvalidArgumentException('Unexpected JWPlayer analytics rows response: ' . $rawResponse);
            }
            array_shift($jwPlayerRows);

            $metrics = $responseJson->metadata->column_headers->metrics;
            $fields = array_column($metrics, 'field');

            foreach ($fields as $key => $field) {
                switch ($field) {
                    case self::JWPLAYER_METRICS_FIELD_TOTAL_PLAYS:
                        $videoStats->setPlays($jwPlayerRows[$key]);
                        break;
                    case self::JWPLAYER_METRICS_FIELD_COMPLETE_RATE:
                        $videoStats->setCompleteRate($jwPlayerRows[$key]);
                        break;
                    case self::JWPLAYER_METRICS_FIELD_UNIQUE_VIEWERS:
                        $videoStats->setUniqueViewers($jwPlayerRows[$key]);
                        break;
                    case self::JWPLAYER_METRICS_FIELD_PLAY_RATE:
                        $videoStats->setPlayRate($jwPlayerRows[$key]);
                        break;
                    case self::JWPLAYER_METRICS_FIELD_EMBEDS:
                        $videoStats->setEmbeds($jwPlayerRows[$key]);
                        break;
                    case self::JWPLAYER_METRICS_FIELD_COMPLETES:
                        $videoStats->setCompletes($jwPlayerRows[$key]);
                        break;
                    case self::JWPLAYER_METRICS_FIELD_CONTENT_SCORE:
                        $videoStats->setContentScore($jwPlayerRows[$key]);
                        break;
                }
            }
        }

        return $videoStats;
    }

    public function createWebhook(CreateWebhookParams $params): CreatedWebhookApiResponse
    {
        $normalizedParams = $this->normalizer->normalize($params);

        $rawResponse = $this->client->requestV2('/webhooks', $normalizedParams);

        try {
            /** @var CreatedWebhookApiResponse $deserialized */
            $deserialized = $this->serializer->deserialize(
                trim($rawResponse),
                CreatedWebhookApiResponse::class,
                'json'
            );
        } catch (SymfonySerializerException $exception) {
            throw new InvalidArgumentException(
                'Unable to deserialize: "' . $rawResponse . '"',
                0,
                $exception
            );
        }

        return $deserialized;
    }

    public function fetchVideos(ListVideosParams $params): VideosList
    {
        $normalizedParams = $this->normalizer->normalize($params);
        $rawResponse = $this->client->requestV1('/videos/list', $normalizedParams);

        try {
            /** @var VideosList $deserialized */
            $deserialized = $this->serializer->deserialize(
                trim($rawResponse),
                VideosList::class,
                'json'
            );
        } catch (SymfonySerializerException $exception) {
            throw new InvalidArgumentException(
                'Unable to deserialize: "' . $rawResponse . '"',
                0,
                $exception
            );
        }

        return $deserialized;
    }

    public function fetchVideo(ShowVideoParams $params): VideoShowResponse
    {
        $normalizedParams = $this->normalizer->normalize($params);
        $rawResponse = $this->client->requestV1('/videos/show', $normalizedParams);

        try {
            /** @var VideoShowResponse $deserialized */
            $deserialized = $this->serializer->deserialize(
                trim($rawResponse),
                VideoShowResponse::class,
                'json'
            );
        } catch (SymfonySerializerException $exception) {
            throw new InvalidArgumentException(
                'Unable to deserialize: "' . $rawResponse . '"',
                0,
                $exception
            );
        }

        return $deserialized;
    }

    public function deleteVideos(DeleteVideosParams $params): DeleteVideosResponse
    {
        $normalizedParams = $this->normalizer->normalize($params);
        $rawResponse = $this->client->requestV1('/videos/delete', $normalizedParams);

        try {
            /** @var DeleteVideosResponse $deserialized */
            $deserialized = $this->serializer->deserialize(
                trim($rawResponse),
                DeleteVideosResponse::class,
                'json'
            );
        } catch (SymfonySerializerException $exception) {
            throw new InvalidArgumentException(
                'Unable to deserialize: "' . $rawResponse . '"',
                0,
                $exception
            );
        }

        return $deserialized;
    }

    public function fetchTracks(ListTracksParams $params): TracksList
    {
        $normalizedParams = $this->normalizer->normalize($params);
        $rawResponse = $this->client->requestV1('/videos/tracks/list', $normalizedParams);

        try {
            /** @var TracksList $deserialized */
            $deserialized = $this->serializer->deserialize(
                trim($rawResponse),
                TracksList::class,
                'json'
            );
        } catch (SymfonySerializerException $exception) {
            throw new InvalidArgumentException(
                'Unable to deserialize: "' . $rawResponse . '"',
                0,
                $exception
            );
        }

        return $deserialized;
    }

    public function deleteTrack(DeleteVideoTrackParams $params): DeleteVideoTrackResponse
    {
        $normalizedParams = $this->normalizer->normalize($params);
        $rawResponse = $this->client->requestV1('/videos/tracks/delete', $normalizedParams);

        try {
            /** @var DeleteVideoTrackResponse $deserialized */
            $deserialized = $this->serializer->deserialize(
                trim($rawResponse),
                DeleteVideoTrackResponse::class,
                'json'
            );
        } catch (SymfonySerializerException $exception) {
            throw new InvalidArgumentException(
                'Unable to deserialize: "' . $rawResponse . '"',
                0,
                $exception
            );
        }

        return $deserialized;
    }

    public function fetchThumbnail(FetchThumbnailParams $params): ThumbnailResource
    {
        $name = "{$params->getVideoKey()}-{$params->getThumbWidth()}.jpg";

        $fileContent = $this->client->download("/thumbs/{$name}");

        $tempFilePath = tempnam(sys_get_temp_dir(), 'thumb');
        $outHandle = fopen($tempFilePath, "w+");
        fwrite($outHandle, $fileContent);
        fclose($outHandle);

        return new ThumbnailResource(
            $name,
            $tempFilePath,
            null//TODO get mimetype?
        );
    }
}
