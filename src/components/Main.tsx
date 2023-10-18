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

const dynamicGraphqlQuery = generateGraphqlQuery(numberOfPosts, categoryName);

const trimUrl = (url: string) => {
  return url.replace(/^https?:\/\/|\/$/g, "");
};
const postListTitle = (
  <h2>
    Recent Posts from{" "}
    <a href={graphqlUrl} target="_blank" rel="noreferrer" title={graphqlUrl}>
      {trimUrl(graphqlUrl)}
    </a>
  </h2>
);

const Main = () => {
  return (
    <main className="main">
      <div className="content-container">
        <PostList
          graphqlUrl={graphqlUrl}
          graphqlEndpoint={graphqlEndpoint}
          graphqlQuery={dynamicGraphqlQuery}
          postListTitle={postListTitle}
        />
        <MediaRenderer mediaArray={mediaData} title="Selected Recent Media" />
      </div>
    </main>
  );
};

export default Main;
