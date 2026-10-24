<?php declare(strict_types=1);

namespace Movary\HttpController\Web;

use Movary\Api\Tmdb\TmdbApi;
use Movary\Api\Tmdb\TmdbUrlGenerator;
use Movary\Domain\Movie\History\MovieHistoryApi;
use Movary\Domain\Movie\MovieApi;
use Movary\Domain\User\UserApi;
use Movary\ValueObject\Http\Request;
use Movary\ValueObject\Http\Response;
use Movary\ValueObject\Http\StatusCode;
use Twig\Environment;

class AnalyticsController
{
    private const array GENRE_NAME_TO_TMDB_ID = [
        'Action' => 28, 'Adventure' => 12, 'Animation' => 16, 'Comedy' => 35,
        'Crime' => 80, 'Documentary' => 99, 'Drama' => 18, 'Family' => 10751,
        'Fantasy' => 14, 'History' => 36, 'Horror' => 27, 'Music' => 10402,
        'Mystery' => 9648, 'Romance' => 10749, 'Science Fiction' => 878,
        'Sci-Fi' => 878, 'Thriller' => 53, 'War' => 10752, 'Western' => 37,
    ];

    public function __construct(
        private readonly Environment $twig,
        private readonly MovieHistoryApi $movieHistoryApi,
        private readonly MovieApi $movieApi,
        private readonly UserApi $userApi,
        private readonly TmdbApi $tmdbApi,
        private readonly TmdbUrlGenerator $tmdbUrlGenerator,
    ) {
    }

    public function renderPage(Request $request) : Response
    {
        $requestedUserId = $this->userApi->fetchUserByName((string)$request->getRouteParameters()['username'])->getId();

        $genres = $this->movieHistoryApi->fetchMostWatchedGenres($requestedUserId);
        $languages = $this->movieHistoryApi->fetchMostWatchedLanguages($requestedUserId);
        $releaseYears = $this->movieHistoryApi->fetchMostWatchedReleaseYears($requestedUserId);
        $watchPerMonth = $this->movieHistoryApi->fetchWatchCountPerMonth($requestedUserId);

        $recommendations = $this->fetchRecommendations($requestedUserId, $genres, $languages);

        return Response::create(
            StatusCode::createOk(),
            $this->twig->render('page/analytics.html.twig', [
                'totalPlayCount' => $this->movieApi->fetchTotalPlayCount($requestedUserId),
                'uniqueMoviesCount' => $this->movieApi->fetchTotalPlayCountUnique($requestedUserId),
                'totalHoursWatched' => $this->movieHistoryApi->fetchTotalHoursWatched($requestedUserId),
                'averagePersonalRating' => $this->movieHistoryApi->fetchAveragePersonalRating($requestedUserId),
                'averageRuntime' => $this->movieHistoryApi->fetchAverageRuntime($requestedUserId),
                'ratingDistribution' => $this->movieHistoryApi->fetchPersonalRatingDistribution($requestedUserId),
                'genres' => array_slice($genres, 0, 12),
                'languages' => array_slice($languages, 0, 10),
                'releaseYears' => array_slice($releaseYears, 0, 20),
                'watchPerMonth' => $watchPerMonth,
                'recommendations' => $recommendations,
            ]),
        );
    }

    /** @param array<array{name: string, count: int}> $genres
     *  @param array<array{language: string, name: string, count: int}> $languages
     *  @return list<array{tmdbId: int, movaryId: int|null, title: string, releaseYear: string|null, posterUrl: string|null, voteAverage: float, isWatched: bool}>
     */
    private function fetchRecommendations(int $userId, array $genres, array $languages) : array
    {
        $filters = ['vote_average.gte' => '7', 'vote_count.gte' => '100'];

        $topGenreName = $genres[0]['name'] ?? null;
        if ($topGenreName !== null && isset(self::GENRE_NAME_TO_TMDB_ID[$topGenreName])) {
            $filters['with_genres'] = (string)self::GENRE_NAME_TO_TMDB_ID[$topGenreName];
        }

        $topLanguage = $languages[0]['language'] ?? null;
        if ($topLanguage !== null) {
            $filters['with_original_language'] = $topLanguage;
        }

        $tmdbResponse = $this->tmdbApi->discoverMovies($filters, 'vote_average.desc');
        $results = $tmdbResponse['results'] ?? [];

        $tmdbIds = array_column($results, 'id');
        $watchedMap = $this->movieHistoryApi->fetchTmdbIdsToLastWatchDatesMap($userId, $tmdbIds);
        $movaryIdMap = [];
        foreach ($this->movieApi->findByTmdbIds($tmdbIds) as $movie) {
            $movaryIdMap[$movie->getTmdbId()] = $movie->getId();
        }

        $recommendations = [];
        foreach ($results as $result) {
            $tmdbId = $result['id'];
            $posterPath = $result['poster_path'] ?? null;
            $recommendations[] = [
                'tmdbId' => $tmdbId,
                'movaryId' => $movaryIdMap[$tmdbId] ?? null,
                'title' => $result['title'],
                'releaseYear' => empty($result['release_date']) ? null : substr($result['release_date'], 0, 4),
                'posterUrl' => $posterPath !== null
                    ? (string)$this->tmdbUrlGenerator->generateImageUrl($posterPath, 'w342')
                    : null,
                'voteAverage' => round((float)($result['vote_average'] ?? 0), 1),
                'isWatched' => isset($watchedMap[$tmdbId]),
            ];
        }

        usort($recommendations, static fn($a, $b) => (int)$a['isWatched'] - (int)$b['isWatched']);

        return array_slice($recommendations, 0, 20);
    }
}
