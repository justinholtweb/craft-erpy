<?php

namespace justinholtweb\erpy\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\erpy\auth\OAuth2AuthorizationCode;
use justinholtweb\erpy\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The OAuth consent round-trip, for the ERPs that require a human to approve access once.
 *
 * The callback is a *front-end* route and anonymous, because several of these providers refuse to
 * register a redirect URI they cannot reach, and none of them will carry a control panel session.
 * The security therefore rests entirely on `state`: a single-use, short-lived, unguessable token
 * minted here and checked there. A callback whose state does not match a token we issued is
 * rejected before the code is ever exchanged.
 */
class OauthController extends Controller
{
    protected array|bool|int $allowAnonymous = ['callback'];

    /** How long the merchant has to complete the consent screen. */
    private const STATE_TTL = 900;

    /**
     * Send the merchant to the ERP's consent screen.
     */
    public function actionConnect(): Response
    {
        $this->requirePermission('erpy-manageConnections');

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredParam('id'));

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $auth = $connection->getConnector()?->auth();

        if (!$auth instanceof OAuth2AuthorizationCode) {
            throw new BadRequestHttpException('This connector does not use OAuth.');
        }

        $state = StringHelper::UUID();

        Craft::$app->getCache()->set(
            $this->stateKey($state),
            ['connectionId' => $connection->id, 'userId' => Craft::$app->getUser()->getId()],
            self::STATE_TTL,
        );

        return $this->redirect($auth->authorizationUrl($state));
    }

    /**
     * Where the ERP sends the merchant back.
     */
    public function actionCallback(): Response
    {
        $state = (string)$this->request->getParam('state', '');
        $code = (string)$this->request->getParam('code', '');
        $error = (string)$this->request->getParam('error', '');

        $payload = $state !== '' ? Craft::$app->getCache()->get($this->stateKey($state)) : false;

        if (!is_array($payload)) {
            // Either somebody is guessing, or the merchant left the consent screen open too long.
            // Both get the same answer, and neither gets to name a connection.
            throw new BadRequestHttpException('That authorisation link has expired. Start again from the connection screen.');
        }

        // Single use, whatever happens next.
        Craft::$app->getCache()->delete($this->stateKey($state));

        $connection = Plugin::getInstance()->getConnections()->getById((int)$payload['connectionId']);

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $target = UrlHelper::cpUrl('erpy/connections/' . $connection->id);

        if ($error !== '') {
            Craft::$app->getSession()->setError(Craft::t('erpy', 'The ERP refused authorisation: {error}', ['error' => $error]));

            return $this->redirect($target);
        }

        $auth = $connection->getConnector()?->auth();

        if (!$auth instanceof OAuth2AuthorizationCode || $code === '') {
            throw new BadRequestHttpException('No authorisation code came back.');
        }

        if ($auth->exchangeCode($code)) {
            Craft::$app->getSession()->setNotice(Craft::t('erpy', 'Connected. {name} can now talk to your ERP.', ['name' => $connection->name]));
        } else {
            Craft::$app->getSession()->setError(Craft::t('erpy', 'The token exchange failed — the connection log has what the ERP said.'));
        }

        return $this->redirect($target);
    }

    /**
     * Forget the tokens, so the merchant is asked to consent again.
     */
    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('erpy-manageConnections');

        $connection = Plugin::getInstance()->getConnections()->getById((int)$this->request->getRequiredBodyParam('id'));

        if (!$connection) {
            throw new NotFoundHttpException('No such connection.');
        }

        $auth = $connection->getConnector()?->auth();

        if ($auth instanceof OAuth2AuthorizationCode) {
            $auth->revoke();
        }

        return $this->asJson(['success' => true]);
    }

    private function stateKey(string $state): string
    {
        return 'erpy:oauth:state:' . $state;
    }
}
