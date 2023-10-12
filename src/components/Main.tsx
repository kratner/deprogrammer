import React from "react";
import MediaRenderer from "./MediaRenderer";
import mediaData from "../data/mediaData.json";
import PostList from "./PostList";
import {
  graphqlUrl,
  graphqlEndpoint,
  generateGraphqlQuery,
} from "../data/graphqlConfig";

const numberOfPosts = 15;
const categoryName = "articles";

const dynamicQgraphqlQuery = generateGraphqlQuery(numberOfPosts, categoryName);

const Main = () => {
  return (
    <main className="main">
      <div className="content-container">
        <PostList
          graphqlUrl={graphqlUrl}
          graphqlEndpoint={graphqlEndpoint}
          graphqlQuery={dynamicQgraphqlQuery}
        />
        <MediaRenderer mediaArray={mediaData} />
      </div>
    </main>
  );
};

export default Main;
