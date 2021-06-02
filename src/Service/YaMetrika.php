<?php

namespace App\Service;

use Carbon\Carbon;
use DateTime;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YaMetrika
{
    use YaMetrikaAdaptDataTrait;

    private HttpClientInterface $client;
    private LoggerInterface $logger;

    /**
     * Id страны(225 - Россия, 187 - Украина... и т.п.) для гео-сервисов Яндекса
     */
    private const RUSSIA_REGION_COUNTRY_ID = 225;

    /**
     * URL YandexMetrika
     */
    private const YA_METRIKA_API_URL = 'https://api-metrika.yandex.net/stat/v1/data';

    /**
     * Id счетчика
     */
    protected string $counterId;

    /**
     * Token
     */
    protected string $token;

    /**
     * Время кэширования в секундах
     */
    protected int $cacheTtl;

    /**
     * Имя метода получения данных
     */
    protected ?string $getMethodName;

    /**
     * Имя метода обработки данных
     */
    protected ?string $adaptMethodName;

    /**
     * Полученные данные с серверов YandexMetrika
     */
    public ?array $data;

    /**
     * Данные прошедшие обработку
     */
    public ?array $adaptData;

    /**
     * YandexMetrika constructor.
     * @link config/services.yaml Passed argument values on initialization [services->App\Service\YaMetrika].
     */
    public function __construct(
        string $counterId,
        string $token,
        int    $cacheTtl,
        HttpClientInterface $client,
        LoggerInterface $logger
    ) {
        $this->counterId = $counterId;
        $this->token     = $token;
        $this->cacheTtl  = $cacheTtl;
        $this->logger    = $logger;
        $this->client    = $client->withOptions([
            'base_uri' => self::YA_METRIKA_API_URL,
            'headers'  => [
                'Content-Type'  => 'application/x-yametrika+json',
                'Authorization' => 'OAuth '.$this->token,
            ],
        ]);
    }

    /**
     * Вызов методов получения данных
     */
    public function __call(string $name, array $arguments): self
    {
        if (method_exists($this, $name)) {

            $this->getMethodName = $name;

            $this->adaptMethodName = str_replace(['get', 'ForPeriod'],
                ['adapt', ''], $this->getMethodName);

            call_user_func_array([$this, $name], $arguments);
        }

        return $this;
    }

    /**
     * Приводим полученные данные в удобочитаемый вид
     */
    public function adapt(): self
    {
        if ($this->data && method_exists($this, $this->adaptMethodName)) {
            $this->{$this->adaptMethodName}();
        }

        return $this;
    }

    /**
     * Установить другой счетчик
     * @todo check if useful, then rm or rewrite.
     */
//    public function setCounter(
//        string $token,
//        string $counterId,
//        ?int   $cacheTtl = null
//    ): self
//    {
//        $this->token     = $token;
//        $this->counterId = $counterId;
//        $this->cacheTtl  = $cacheTtl ?: config('yandex-metrika.cache');
//
//        return $this;
//    }

    /**
     * Получаем кол-во: визитов, просмотров, уникальных посетителей по дням,
     * за выбранное кол-во дней
     */
    protected function getVisitsViewsUsers(int $days = 30): void
    {
        [$startDate, $endDate] = $this->calculateDays($days);

        $this->getVisitsViewsUsersForPeriod($startDate, $endDate);
    }

    /**
     * Получаем кол-во: визитов, просмотров, уникальных посетителей по дням,
     * за выбранный период
     *
     * @param DateTime $startDate
     * @param DateTime $endDate
     *
     */
    protected function getVisitsViewsUsersForPeriod(
        DateTime $startDate,
        DateTime $endDate
    ): void
    {
        $cacheName = md5(serialize('visits-views-users'
                                   .$startDate->format('Y-m-d').$endDate->format('Y-m-d')));

        $urlParams = [
            'ids'        => $this->counterId,
            'date1'      => $startDate->format('Y-m-d'),
            'date2'      => $endDate->format('Y-m-d'),
            'metrics'    => 'ym:s:visits,ym:s:pageviews,ym:s:users',
            'dimensions' => 'ym:s:date',
            'sort'       => 'ym:s:date',
        ];

        $this->data = $this->request($urlParams, $cacheName);
    }

    /**
     * Самые просматриваемые страницы за $days, количество - $maxResults
     */
    protected function getTopPageViews(
        int $days       = 30,
        int $maxResults = 10
    ): void
    {
        [$startDate, $endDate] = $this->calculateDays($days);

        $this->getTopPageViewsForPeriod($startDate, $endDate, $maxResults);
    }

    /**
     * Самые просматриваемые страницы за выбранный период, количество - $maxResults
     */
    protected function getTopPageViewsForPeriod(
        DateTime $startDate,
        DateTime $endDate,
        int      $maxResults = 10
    ): void
    {
        $cacheName = md5(serialize('top-pages-views'.$startDate->format('Y-m-d')
                                   .$endDate->format('Y-m-d').$maxResults));

        //Параметры запроса
        $urlParams = [
            'ids'        => $this->counterId,
            'date1'      => $startDate->format('Y-m-d'),
            'date2'      => $endDate->format('Y-m-d'),
            'metrics'    => 'ym:pv:pageviews',
            'dimensions' => 'ym:pv:URLPathFull,ym:pv:title',
            'sort'       => '-ym:pv:pageviews',
            'limit'      => $maxResults,
        ];

        $this->data = $this->request($urlParams, $cacheName);
    }

    /**
     * Отчет "Источники - Сводка" за последние $days дней
     */
    protected function getSourcesSummary(int $days = 30): void
    {
        [$startDate, $endDate] = $this->calculateDays($days);

        $this->getSourcesSummaryForPeriod($startDate, $endDate);
    }

    /**
     * Отчет "Источники - Сводка" за период
     */
    protected function getSourcesSummaryForPeriod(
        DateTime $startDate,
        DateTime $endDate
    ): void
    {
        $cacheName = md5(serialize('sources-summary'.$startDate->format('Y-m-d')
                                   .$endDate->format('Y-m-d')));

        $urlParams = [
            'ids'    => $this->counterId,
            'date1'  => $startDate->format('Y-m-d'),
            'date2'  => $endDate->format('Y-m-d'),
            'preset' => 'sources_summary',
        ];

        $this->data = $this->request($urlParams, $cacheName);
    }

    /**
     * Отчет "Источники - Поисковые фразы" за $days дней, кол-во результатов - $maxResults
     */
    protected function getSourcesSearchPhrases(
        int $days       = 30,
        int $maxResults = 10
    ): void
    {
        [$startDate, $endDate] = $this->calculateDays($days);

        $this->getSourcesSearchPhrasesForPeriod($startDate, $endDate,
            $maxResults);
    }

    /**
     * Отчет "Источники - Поисковые фразы" за период, кол-во результатов - $maxResults
     */
    protected function getSourcesSearchPhrasesForPeriod(
        DateTime $startDate,
        DateTime $endDate,
        int      $maxResults = 10
    ): void
    {
        $cacheName = md5(serialize('sources-search-phrases'
                                   .$startDate->format('Y-m-d').$endDate->format('Y-m-d').$maxResults));

        $urlParams = [
            'ids'    => $this->counterId,
            'date1'  => $startDate->format('Y-m-d'),
            'date2'  => $endDate->format('Y-m-d'),
            'preset' => 'sources_search_phrases',
            'limit'  => $maxResults,
        ];

        $this->data = $this->request($urlParams, $cacheName);
    }

    /**
     * Отчет "Технологии - Браузеры" за $days дней, кол-во результатов - $maxResults
     */
    protected function getTechPlatforms(
        int $days       = 30,
        int $maxResults = 10
    ): void
    {
        [$startDate, $endDate] = $this->calculateDays($days);

        $this->getTechPlatformsForPeriod($startDate, $endDate, $maxResults);
    }

    /**
     * Отчет "Технологии - Браузеры" за период, кол-во результатов - $maxResults
     */
    protected function getTechPlatformsForPeriod(
        DateTime $startDate,
        DateTime $endDate,
        int      $maxResults = 10
    ): void
    {
        $cacheName = md5(serialize('tech_platforms'.$startDate->format('Y-m-d')
                                   .$endDate->format('Y-m-d').$maxResults));

        $urlParams = [
            'ids'        => $this->counterId,
            'date1'      => $startDate->format('Y-m-d'),
            'date2'      => $endDate->format('Y-m-d'),
            'preset'     => 'tech_platforms',
            'dimensions' => 'ym:s:browser',
            'limit'      => $maxResults,
        ];

        $this->data = $this->request($urlParams, $cacheName);
    }

    /**
     * Количество визитов и посетителей с учетом поисковых систем за $days дней
     */
    protected function getVisitsUsersSearchEngine(
        int $days       = 30,
        int $maxResults = 10
    ): void
    {
        [$startDate, $endDate] = $this->calculateDays($days);

        $this->getVisitsUsersSearchEngineForPeriod($startDate, $endDate,
            $maxResults);
    }

    /**
     * Количество визитов и посетителей с учетом поисковых систем за период
     */
    protected function getVisitsUsersSearchEngineForPeriod(
        DateTime $startDate,
        DateTime $endDate,
        int      $maxResults = 10
    ): void
    {
        $cacheName = md5(serialize('visits-users-searchEngine'
                                   .$startDate->format('Y-m-d').$endDate->format('Y-m-d').$maxResults));

        $urlParams = [
            'ids'        => $this->counterId,
            'date1'      => $startDate->format('Y-m-d'),
            'date2'      => $endDate->format('Y-m-d'),
            'metrics'    => 'ym:s:users',
            'dimensions' => 'ym:s:searchEngine',
            'filters'    => "ym:s:trafficSource=='organic'",
            'limit'      => $maxResults,
        ];

        $this->data = $this->request($urlParams, $cacheName);
    }

    /**
     * Количество визитов с глубиной просмотра больше $pages страниц, за $days дней
     */
    protected function getVisitsViewsPageDepth(
        int $days  = 30,
        int $pages = 5
    ): void
    {
        [$startDate, $endDate] = $this->calculateDays($days);

        $this->getVisitsViewsPageDepthForPeriod($startDate, $endDate, $pages);
    }

    /**
     * Количество визитов с глубиной просмотра больше $pages страниц, за период
     */
    protected function getVisitsViewsPageDepthForPeriod(
        DateTime $startDate,
        DateTime $endDate,
        int      $pages = 5
    ): void
    {
        $cacheName = md5(serialize('visits-views-page-depth'
                                   .$startDate->format('Y-m-d').$endDate->format('Y-m-d').$pages));

        //Параметры запроса
        $urlParams = [
            'ids'     => $this->counterId,
            'date1'   => $startDate->format('Y-m-d'),
            'date2'   => $endDate->format('Y-m-d'),
            'metrics' => 'ym:s:visits',
            'filters' => 'ym:s:pageViews>'.$pages,
        ];

        $this->data = $this->request($urlParams, $cacheName);
    }

    /**
     * Отчеты о посещаемости сайта с распределением по странам и регионам, за последние $days,
     * кол-во результатов - $maxResults
     */
    protected function getGeoCountry(
        int $days       = 7,
        int $maxResults = 100
    ): void
    {
        [$startDate, $endDate] = $this->calculateDays($days);

        $this->getGeoCountryForPeriod($startDate, $endDate, $maxResults);
    }

    /**
     * Отчеты о посещаемости сайта с распределением по странам и регионам, за период
     */
    protected function getGeoCountryForPeriod(
        DateTime $startDate,
        DateTime $endDate,
        int      $maxResults = 100
    ): void
    {
        $cacheName = md5(serialize('geo_country'.$startDate->format('Y-m-d')
                                   .$endDate->format('Y-m-d').$maxResults));

        //Параметры запроса
        $urlParams = [
            'ids'        => $this->counterId,
            'date1'      => $startDate->format('Y-m-d'),
            'date2'      => $endDate->format('Y-m-d'),
            'dimensions' => 'ym:s:regionCountry,ym:s:regionArea',
            'metrics'    => 'ym:s:visits',
            'sort'       => '-ym:s:visits',
            'limit'      => $maxResults,
        ];

        $this->data = $this->request($urlParams, $cacheName);
    }

    /**
     * Отчеты о посещаемости сайта с распределением по областям и городам, за последние $days,
     * кол-во результатов - $maxResults, $countryId - id страны(225 - Россия, 187 - Украина... и т.п.)
     */
    protected function getGeoArea(
        int $days       = 7,
        int $maxResults = 100,
        int $countryId  = null
    ): void
    {
        $countryId = $countryId ?: self::RUSSIA_REGION_COUNTRY_ID;

        [$startDate, $endDate] = $this->calculateDays($days);

        $this->getGeoAreaForPeriod($startDate, $endDate, $maxResults,
            $countryId);
    }

    /**
     * Отчеты о посещаемости сайта с распределением по областям и городам, за период
     */
    protected function getGeoAreaForPeriod(
        DateTime $startDate,
        DateTime $endDate,
        int      $maxResults = 100,
        int      $countryId  = null
    ): void
    {
        $countryId = $countryId ?: self::RUSSIA_REGION_COUNTRY_ID;

        $cacheName = md5(serialize('geo_region'.$startDate->format('Y-m-d')
                                   .$endDate->format('Y-m-d').$maxResults.$countryId));

        $urlParams = [
            'ids'        => $this->counterId,
            'date1'      => $startDate->format('Y-m-d'),
            'date2'      => $endDate->format('Y-m-d'),
            'dimensions' => 'ym:s:regionArea,ym:s:regionCity',
            'metrics'    => 'ym:s:visits',
            'sort'       => '-ym:s:visits',
            'filters'    => "ym:s:regionCountry=='$countryId'",
            'limit'      => $maxResults,
        ];

        $this->data = $this->request($urlParams, $cacheName);
    }

    /**
     * Произвольный запрос к Api Yandex Metrika
     * Пример:
     * $urlParams = [
     *      'ids'     => id счетчика,
     *      'date1'   => Дата в формате 'Y-m-d',
     *      'date2'   => Дата в формате 'Y-m-d',
     *      'filters' => 'ym:s:pageViews>5',
     *      'metrics' => 'ym:s:visits'
     * ]
     *
     */
    public function getRequestToApi(array $urlParams): self
    {
        $cacheName  = md5(serialize($urlParams));
        $this->data = $this->request($urlParams, $cacheName);

        return $this;
    }

    /**
     * GET запрос данных и кэширование
     */
    protected function request(array $urlParams,string $name): ?array
    {
        try {
            $cache = new FilesystemAdapter();
            $cachedRequest = $cache->getItem($this->counterId.'_'.$name);
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Yandex Metrika: '.$e->getMessage());

            return null;
        }

        if (!$cachedRequest->isHit()) {
            try {
                $result = $this->client->request(
                    'GET',
                    '',
                    ['query' => $urlParams]
                )->toArray();
            } catch (ExceptionInterface $e) {
                // todo: create separate handlers to more specific Exceptions?
                $this->logger->error('Yandex Metrika: '.$e->getMessage());
                $result = null;
            }
            if ($result) {
                $cachedRequest->set($result)->expiresAfter($this->cacheTtl);
                $cache->save($cachedRequest);
            }

            return $result;
        }

        return $cachedRequest->get();
    }

    /**
     * Вычисляем даты
     */
    protected function calculateDays(int $numberOfDays): array
    {
        $endDate   = Carbon::today();
        $startDate = Carbon::today()->subDays($numberOfDays);

        return [$startDate, $endDate];
    }
}
